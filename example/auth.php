<?php
require_once __DIR__ . '/../onedrive-config.php';
require_once __DIR__ . '/../onedrive.php';

// If we don't have a code in the query string (meaning that the user did not
// log in successfully or did not grant privileges requested), we cannot proceed
// in obtaining an access token
if (!array_key_exists('code', $_GET)) {
	throw new Exception('code undefined in $_GET');
}

session_start();

// Attempt to load the OneDrive client' state persisted from the previous
// request
if (!array_key_exists('onedrive.client.state', $_SESSION)) {
	throw new Exception('onedrive.client.state undefined in $_SESSION');
}

$onedrive = new \Onedrive\Client(array(
	'client_id' => ONEDRIVE_CLIENT_ID,

	// Restore the previous state while instantiating this client to proceed in
	// obtaining an access token
	'state'     => $_SESSION['onedrive.client.state']
));

// Obtain the token using the code received by the OneDrive API
$onedrive->obtainAccessToken(ONEDRIVE_CLIENT_SECRET, $_GET['code']);

// Persist the OneDrive client' state for next API requests
$_SESSION['onedrive.client.state'] = $onedrive->getState();
?>
<!DOCTYPE html>
<html lang=en dir=ltr>
	<head>
		<meta charset=utf-8>
		<title>Authentication complete – Demonstration of the OneDrive SDK for PHP</title>
		<link rel=stylesheet href=//ajax.aspnetcdn.com/ajax/bootstrap/3.2.0/css/bootstrap.min.css>
		<link rel=stylesheet href=//ajax.aspnetcdn.com/ajax/bootstrap/3.2.0/css/bootstrap-theme.min.css>
		<meta name=viewport content="width=device-width, initial-scale=1">
	</head>
	<body>
		<div class=container>
			<h1>Authentication complete</h1>
			<div class=jumbotron>
				<p><a href=app.php class="btn btn-primary btn-lg">Proceed to the application</a></p>
			</div>
		</div>
	</body>
</html>
