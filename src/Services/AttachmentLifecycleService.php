<?php

/**
 * Bridges WordPress attachment upload/delete events to folder counters.
 *
 * Both hooks queue their work and flush at `shutdown` so bulk uploads
 * collapse to a single round-trip. See docs/03_1_MEDIA_folder-system.md
 * for the counter contract.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

class AttachmentLifecycleService
{
    private FolderCounterService $counters;

    /** @var array<int, int> attachmentId => filesize bytes pending root +1 */
    private array $pendingAdditions = [];

    /** @var array<int, array{folderId:int, size:int}> attachmentId => deletion data */
    private array $pendingDeletions = [];

    /** @var bool Whether shutdown hook has been registered for this request */
    private bool $shutdownRegistered = false;

    /**
     * @param FolderCounterService $counters Chain-delta + Root cache writer.
     */
    public function __construct(FolderCounterService $counters)
    {
        $this->counters = $counters;
    }

    /**
     * Wire up WordPress hooks
     *
     * @return void
     */
    public function register(): void
    {
        add_filter('wp_generate_attachment_metadata', [$this, 'onMetadataGenerated'], 10, 3);
        add_action('delete_attachment', [$this, 'onAttachmentDelete'], 10, 1);
    }

    /**
     * Queue a `(filesize, +1)` Root counter delta for a new upload.
     *
     * Skipped when $context !== 'create' (regenerations must not bump the
     * counter). Returns $metadata unchanged.
     *
     * @param mixed  $metadata     The attachment metadata array.
     * @param int    $attachmentId Attachment post ID.
     * @param string $context      'create' for initial upload, 'update' otherwise.
     *
     * @return mixed
     */
    public function onMetadataGenerated($metadata, $attachmentId, $context = 'create')
    {
        if ('create' !== $context) {
            return $metadata;
        }

        $size = 0;
        if (is_array($metadata) && isset($metadata['filesize']) && is_numeric($metadata['filesize'])) {
            $size = (int) $metadata['filesize'];
        }

        $this->pendingAdditions[(int) $attachmentId] = $size;
        $this->ensureShutdownHook();

        return $metadata;
    }

    /**
     * Read the folder assignment + filesize and queue a deletion entry.
     *
     * Runs while the post still exists; the queued entry is flushed at
     * shutdown.
     *
     * @param int $attachmentId Attachment post ID.
     *
     * @return void
     */
    public function onAttachmentDelete($attachmentId): void
    {
        $attachmentId = (int) $attachmentId;
        if ($attachmentId <= 0) {
            return;
        }

        $folderId = 0;
        $terms    = wp_get_object_terms($attachmentId, TaxonomyService::TAXONOMY_NAME, ['fields' => 'ids']);
        if (is_array($terms) && ! empty($terms) && is_numeric($terms[0])) {
            $folderId = (int) $terms[0];
        }

        $size = 0;
        $meta = get_post_meta($attachmentId, '_wp_attachment_metadata', true);
        if (is_array($meta) && isset($meta['filesize']) && is_numeric($meta['filesize'])) {
            $size = (int) $meta['filesize'];
        }

        $this->pendingDeletions[$attachmentId] = [
            'folderId' => $folderId,
            'size'     => $size,
        ];
        $this->ensureShutdownHook();
    }

    /**
     * Flush both queues at end of request
     *
     * Public so tests can drive it directly without simulating shutdown.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->flushAdditions();
        $this->flushDeletions();
    }

    /**
     * Apply pending additions to the global Root counters
     *
     * @return void
     */
    private function flushAdditions(): void
    {
        if (empty($this->pendingAdditions)) {
            return;
        }

        $sizeDelta  = 0;
        $countDelta = 0;
        foreach ($this->pendingAdditions as $size) {
            $sizeDelta  += $size;
            $countDelta += 1;
        }

        $this->pendingAdditions = [];

        $this->counters->adjustRoot($sizeDelta, $countDelta);
    }

    /**
     * Apply pending deletions to ancestor chains and Root
     *
     * @return void
     */
    private function flushDeletions(): void
    {
        if (empty($this->pendingDeletions)) {
            return;
        }

        /** @var array<int, array{count:int, size:int}> $byFolder */
        $byFolder  = [];
        $rootSize  = 0;
        $rootCount = 0;

        foreach ($this->pendingDeletions as $entry) {
            $folderId = (int) $entry['folderId'];
            $size     = (int) $entry['size'];

            $rootSize  += $size;
            $rootCount += 1;

            if ($folderId > 0) {
                if (! isset($byFolder[$folderId])) {
                    $byFolder[$folderId] = [
                        'count' => 0,
                        'size'  => 0,
                    ];
                }
                $byFolder[$folderId]['count'] += 1;
                $byFolder[$folderId]['size']  += $size;
            }
        }

        $this->pendingDeletions = [];

        foreach ($byFolder as $folderId => $delta) {
            $chain = FolderTreeNavigator::ancestorIds($folderId);
            $this->counters->applyChainDelta($chain, -$delta['size'], -$delta['count']);
        }

        $this->counters->adjustRoot(-$rootSize, -$rootCount);
    }

    /**
     * Register the shutdown handler at most once per request
     *
     * @return void
     */
    private function ensureShutdownHook(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        add_action('shutdown', [$this, 'flush'], 100);
    }
}
