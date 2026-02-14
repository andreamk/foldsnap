import { render, screen } from '@testing-library/react';
import App from '../App';

describe( 'App component', () => {
	it( 'renders the FoldSnap heading', () => {
		render( <App /> );
		expect( screen.getByText( 'FoldSnap' ) ).toBeInTheDocument();
	} );

	it( 'renders the working message', () => {
		render( <App /> );
		expect( screen.getByText( 'React is working!' ) ).toBeInTheDocument();
	} );
} );
