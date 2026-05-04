/**
 * Immutably set the children list for a parent in a foldersByParent map.
 *
 * Returns a new outer object with only the affected parent slot replaced;
 * other parents share reference identity so memoised selectors remain stable.
 *
 * @param {Object<number, Array>} foldersByParent Current map (parentId → folders).
 * @param {number}                parentId        Parent ID being updated.
 * @param {Array}                 folders         Children of that parent.
 * @return {Object<number, Array>} New map with the parent slot replaced.
 */
export const setChildrenForParent = (
	foldersByParent,
	parentId,
	folders
) => ( {
	...foldersByParent,
	[ parentId ]: folders,
} );

/**
 * Append a page of children to an existing parent slot, deduped by id.
 *
 * Used by `loadMoreChildren`: each pagination tick adds the new page to the
 * tail of the previously-loaded list. Folders already present (matching id)
 * are replaced with the latest copy so totals stay fresh.
 *
 * @param {Object<number, Array>} foldersByParent Current map.
 * @param {number}                parentId        Parent ID being extended.
 * @param {Array}                 newFolders      Newly fetched page of children.
 * @return {Object<number, Array>} New map with the merged list.
 */
export const appendChildrenForParent = (
	foldersByParent,
	parentId,
	newFolders
) => {
	const existing = foldersByParent[ parentId ] ?? [];
	const byId = new Map();
	for ( const folder of existing ) {
		byId.set( folder.id, folder );
	}
	for ( const folder of newFolders ) {
		byId.set( folder.id, folder );
	}
	return {
		...foldersByParent,
		[ parentId ]: Array.from( byId.values() ),
	};
};

/**
 * Remove a folder (and any of its descendants present) from the map.
 *
 * Drops the folder from its parent's children list and deletes the slot
 * keyed by the folder itself (so its own loaded children are released).
 *
 * @param {Object<number, Array>} foldersByParent Current map.
 * @param {number}                parentId        Parent that contained the folder.
 * @param {number}                folderId        Folder being removed.
 * @return {Object<number, Array>} New map without the folder and its slot.
 */
export const removeFolderFromMap = ( foldersByParent, parentId, folderId ) => {
	const next = { ...foldersByParent };
	if ( next[ parentId ] ) {
		next[ parentId ] = next[ parentId ].filter(
			( f ) => f.id !== folderId
		);
	}
	delete next[ folderId ];
	return next;
};

/**
 * Replace a single folder in-place wherever it appears in the map.
 *
 * Used after mutations that update totals or has_children on existing
 * folders without changing parentage. Iterates every parent slot since the
 * folder may sit in only one of them and we don't want to track that here.
 *
 * @param {Object<number, Array>} foldersByParent Current map.
 * @param {Object}                folder          Updated folder object.
 * @return {Object<number, Array>} New map with the folder's slot replaced.
 */
export const replaceFolderInMap = ( foldersByParent, folder ) => {
	const next = {};
	let changed = false;
	for ( const [ parentId, folders ] of Object.entries( foldersByParent ) ) {
		const idx = folders.findIndex( ( f ) => f.id === folder.id );
		if ( idx === -1 ) {
			next[ parentId ] = folders;
			continue;
		}
		const copy = folders.slice();
		copy[ idx ] = folder;
		next[ parentId ] = copy;
		changed = true;
	}
	return changed ? next : foldersByParent;
};
