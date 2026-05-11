import '@testing-library/jest-dom';

// Mirror what wp_localize_script injects in production. Tests that need
// different values can override individual fields in their own beforeEach.
window.foldsnap_data = {
	restUrl: '/wp-json/foldsnap/v1/',
	mediaMode: 'grid',
	preferences: {},
	sidebarWidthMin: 200,
	sidebarWidthMax: 600,
	foldersPerPage: 100,
	foldersMaxPerPage: 200,
	searchPerPage: 50,
	mediaPerPage: 40,
};
