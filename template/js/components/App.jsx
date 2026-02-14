import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const App = () => {
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'FoldSnap', 'foldsnap' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>{ __( 'React is working!', 'foldsnap' ) }</p>
			</CardBody>
		</Card>
	);
};

export default App;
