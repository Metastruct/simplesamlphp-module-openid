<?php

#require_once('../../_include.php');
require_once('Auth/OpenID/SReg.php');
require_once('Auth/OpenID/Server.php');
require_once('Auth/OpenID/ServerRequest.php');

$config = SimpleSAML_Configuration::getInstance();

/* Find the authentication state. */
if (!array_key_exists('AuthState', $_REQUEST)) {
	throw new SimpleSAML_Error_BadRequest('Missing mandatory parameter: AuthState');
}
$state = SimpleSAML_Auth_State::loadState($_REQUEST['AuthState'], 'openid:state');
$authState = $_REQUEST['AuthState'];
$authSource = SimpleSAML_Auth_Source::getById($state['openid:AuthId']);
if ($authSource === NULL) {
	throw new SimpleSAML_Error_BadRequest('Invalid AuthId \'' . $state['feide:AuthId'] . '\' - not found.');
}


function displayError($message) {
    $error = $message;

	$config = SimpleSAML_Configuration::getInstance();
	$t = new SimpleSAML_XHTML_Template($config, 'openid:consumer.php', 'openid');
	$t->data['msg'] = $msg;
	$t->data['error'] = $error;
	$t->show();
}


function &getStore() {
    /**
     * This is where the example will store its OpenID information.
     * You should change this path if you want the example store to be
     * created elsewhere.  After you're done playing with the example
     * script, you'll have to remove this directory manually.
     */
    $store_path = "/tmp/_php_consumer_test";

    if (!file_exists($store_path) &&
        !mkdir($store_path)) {
        print "Could not create the FileStore directory '$store_path'. ".
            " Please check the effective permissions.";
        exit(0);
    }

    return new Auth_OpenID_FileStore($store_path);
}

function &getConsumer() {
    /**
     * Create a consumer object using the store object created
     * earlier.
     */
    $store = getStore();
    return new Auth_OpenID_Consumer($store);
}

function getOpenIDURL() {
    // Render a default page if we got a submission without an openid
    // value.
    if (empty($_GET['openid_url'])) {
        $error = "Expected an OpenID URL.";

		$config = SimpleSAML_Configuration::getInstance();
		$t = new SimpleSAML_XHTML_Template($config, 'openid:consumer.php', 'openid');
		$t->data['msg'] = $msg;
		$t->data['error'] = $error;
		$t->show();
    }

    return $_GET['openid_url'];
}

function getReturnTo() {
	return SimpleSAML_Utilities::addURLparameter(SimpleSAML_Utilities::selfURL(), 
		array('returned' => '1') 
	);

}

function getTrustRoot() {
	return SimpleSAML_Utilities::selfURLhost();
}

function run_try_auth() {
    $openid = getOpenIDURL();
    $consumer = getConsumer();

    // Begin the OpenID authentication process.
    $auth_request = $consumer->begin($openid);

    // No auth request means we can't begin OpenID.
    if (!$auth_request) {
        displayError("Authentication error; not a valid OpenID.");
    }

    $sreg_request = Auth_OpenID_SRegRequest::build(
			array('nickname'), // Required
			array('fullname', 'email')); // Optional

    if ($sreg_request) {
        $auth_request->addExtension($sreg_request);
    }

    // Redirect the user to the OpenID server for authentication.
    // Store the token for this authentication so we can verify the
    // response.

    // For OpenID 1, send a redirect.  For OpenID 2, use a Javascript
    // form to send a POST request to the server.
    if ($auth_request->shouldSendRedirect()) {
        $redirect_url = $auth_request->redirectURL(getTrustRoot(), getReturnTo());

        // If the redirect URL can't be built, display an error message.
        if (Auth_OpenID::isFailure($redirect_url)) {
            displayError("Could not redirect to server: " . $redirect_url->message);
        } else {
            header("Location: ".$redirect_url); // Send redirect.
        }
    } else {
        // Generate form markup and render it.
        $form_id = 'openid_message';
        $form_html = $auth_request->formMarkup(getTrustRoot(), getReturnTo(), FALSE, array('id' => $form_id));

        // Display an error if the form markup couldn't be generated; otherwise, render the HTML.
        if (Auth_OpenID::isFailure($form_html)) {
            displayError("Could not redirect to server: " . $form_html->message);
        } else {
            echo '<html><head><title>OpenID transaction in progress</title></head>
            		<body onload=\'document.getElementById("' . $form_id . '").submit()\'>' . 
					$form_html . '</body></html>';
        }
    }
}

function run_finish_auth() {

	$error = 'General error. Try again.';

	try {
	
		$consumer = getConsumer();
	
		// Complete the authentication process using the server's
		// response.
		$response = $consumer->complete();
	
		// Check the response status.
		if ($response->status == Auth_OpenID_CANCEL) {
			// This means the authentication was cancelled.
			throw new Exception('Verification cancelled.');
		} else if ($response->status == Auth_OpenID_FAILURE) {
			// Authentication failed; display the error message.
			throw new Exception("OpenID authentication failed: " . $response->message);
		} else if ($response->status == Auth_OpenID_SUCCESS) {
			// This means the authentication succeeded; extract the
			// identity URL and Simple Registration data (if it was
			// returned).
			$openid = $response->identity_url;
	
			$attributes = array('openid' => array($openid));
	
			if ($response->endpoint->canonicalID) {
				$attributes['openid.canonicalID'] = array($response->endpoint->canonicalID);
			}
	
			$sreg_resp = Auth_OpenID_SRegResponse::fromSuccessResponse($response);
			$sregresponse = $sreg_resp->contents();
			
			if (is_array($sregresponse) && count($sregresponse) > 0) {
				$attributes['openid.sregkeys'] = array_keys($sregresponse);
				foreach ($sregresponse AS $sregkey => $sregvalue) {
					$attributes['openid.sreg.' . $sregkey] = array($sregvalue);
				}
			}

			global $state;
			$state['Attributes'] = $attributes;
			SimpleSAML_Auth_Source::completeAuth($state);
			
		}

	} catch (Exception $e) {
		$error = $e->getMessage();
	}

	$config = SimpleSAML_Configuration::getInstance();
	$t = new SimpleSAML_XHTML_Template($config, 'openid:consumer.php', 'openid');
	$t->data['error'] = $error;
	global $authState;
	$t->data['AuthState'] = $authState;
	$t->show();

}

if (array_key_exists('returned', $_GET)) {
	run_finish_auth();
} elseif(array_key_exists('openid_url', $_GET)) {
	run_try_auth();
} else {
	$config = SimpleSAML_Configuration::getInstance();
	$t = new SimpleSAML_XHTML_Template($config, 'openid:consumer.php', 'openid');
	global $authState;
	$t->data['AuthState'] = $authState;
	$t->show();
}



?>