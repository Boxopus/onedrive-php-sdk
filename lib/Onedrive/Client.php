<?php
namespace Onedrive;

use Onedrive\Exception\BadRequestException;
use Onedrive\Exception\InvalidTokenException;
use Onedrive\Exception\NotAuthorizedTokenException;
use Onedrive\Exception\NotFoundException;
use Onedrive\Exception\InvalidMethodException;
use Onedrive\Exception\RequestTimeoutException;
use Onedrive\Exception\EditConflictException;
use Onedrive\Exception\EndpointNotSupportedException;
use Onedrive\Exception\RequestBodyException;
use Onedrive\Exception\RequestMediaTypeException;
use Onedrive\Exception\RequestThrottledException;
use Onedrive\Exception\RequestLockedException;
use Onedrive\Exception\ConnectionException;
use Onedrive\Exception\ServerException;
use Onedrive\Exception\StorageLimitException;

/*
 * A Client instance allows communication with the OneDrive API and perform
 * operations programmatically.
 *
 * For an overview of the OneDrive protocol flow, see here:
 * http://msdn.microsoft.com/en-us/library/live/hh243647.aspx
 *
 * To manage your Live Connect applications, see here:
 * https://account.live.com/developers/applications/index
 * Or here:
 * https://manage.dev.live.com/ (not working?)
 *
 * For an example implementation, see here:
 * https://github.com/drumaddict/skydrive-api-yii/blob/master/SkyDriveAPI.php
 */
// TODO: pass parameters in POST request body when obtaining the access token
class Client
{
    // The base URL for API requests.
    const API_URL = 'https://apis.live.net/v5.0/';
    
    // The base URL for authorization requests.
    const AUTH_URL = 'https://login.live.com/oauth20_authorize.srf';
    
    // The base URL for token requests.
    const TOKEN_URL = 'https://login.live.com/oauth20_token.srf';
    
    // Client information.
    private $_clientId;
    
    // OAuth state (token, etc...).
    private $_state;
    
    // The last HTTP status received.
    private $_httpStatus;
    
    // The last Content-Type received.
    private $_contentType;

    /**
     * Creates a base cURL object which is compatible with the OneDrive API.
     *
     * @param (string) $path
     *            - The path of the API call (eg. me/skydrive).
     * @return (resource) A compatible cURL object.
     */
    private static function _createCurl($path)
    {
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            // General options.
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            
            // SSL options.
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        return $curl;
    }

    /**
     * Processes a result returned by the OneDrive API call using a cURL object.
     *
     * @param (resource) $curl
     *            - The cURL object used to perform the call.
     * @return (object|string) The content returned, as an object instance if
     *         served a JSON, or as a string if served as anything else.
     */
    private function _processResult($curl)
    {
        $result = curl_exec($curl);
        if (false === $result) {
            throw new \Exception('curl_exec() failed: ' . curl_error($curl));
        }
        
        $info = curl_getinfo($curl);
        
        $this->_httpStatus = isset($info['http_code']) ? (int) $info['http_code'] : null;
        switch ($this->_httpStatus) {
            case 400:
                throw new BadRequestException('Bad request', $this->_httpStatus);
            case 401:
                throw new InvalidTokenException('The token was invalid, missing, or expired', $this->_httpStatus);
            case 403:
                throw new NotAuthorizedTokenException('The token was not authorized, or the user has not been granted permissions', $this->_httpStatus);
            case 404:
                throw new NotFoundException('The resource was not found', $this->_httpStatus);
            case 405:
                throw new InvalidMethodException('An invalid method was used', $this->_httpStatus);
            case 408:
                throw new RequestTimeoutException('The request timed out' . $this->_httpStatus);
            case 409:
                throw new EditConflictException('The request failed, due to an edit conflict', $this->_httpStatus);
            case 410:
                throw new EndpointNotSupportedException('The endpoint or scenario is no longer supported', $this->_httpStatus);
            case 413:
                throw new RequestBodyException('The request entity body was too large', $this->_httpStatus);
            case 415:
                throw new RequestMediaTypeException('The request entity body was an invalid media type', $this->_httpStatus);
            case 420:
                throw new RequestThrottledException('The request was throttled. Throttling occurs on a per/app and per/user basis', $this->_httpStatus);
            case 423:
                throw new RequestLockedException('The requested resource is locked and can\'t be updated', $this->_httpStatus);
            case 499:
                throw new ConnectionException('There is a connection issue with the client', $this->_httpStatus);
            case 500:
                throw new ServerException('The server had an unexpected error', $this->_httpStatus);
            case 503:
                throw new ServerException('The server is unavailable', $this->_httpStatus);
            case 507:
                throw new StorageLimitException('The user doesn\'t have enough available storage', $this->_httpStatus);
        }
        $this->_contentType = isset($info['content_type']) ? $info['content_type'] : null;
        
        // Parse nothing but JSON.
        if (1 !== preg_match('|^application/json|', $this->_contentType)) {
            return $result;
        }
        
        // Empty JSON string is returned as an empty object.
        if ('' == $result) {
            return new \stdClass();
        }
        
        $decoded = json_decode($result);
        $vars = get_object_vars($decoded);
        
        if (isset($vars['error'])) {
            throw new \Exception($decoded->error->message, (int) $decoded->error->code);
        }
        
        return $decoded;
    }

    /**
     * Constructor.
     *
     * @param (array) $options.
     *            The options to use while creating this object.
     *            The only supported key is 'state'. When defined, it should contain
     *            a valid OneDrive client state, as returned by getState(). Default:
     *            array().
     */
    public function __construct(array $options = [])
    {
        $this->_clientId = isset($options['client_id']) ? (string) $options['client_id'] : null;
        
        if (isset($options['state'])) {
            $this->_state = (object) $options['state'];
        } else {
            $this->_state = new \stdClass();
            $this->_state->redirect_uri = null;
            $this->_state->token = null;
        }
    }

    /**
     * Gets the current state of this Client instance.
     * Typically saved in the
     * session and passed back to the Client constructor for further requests.
     *
     * @return (object) The state of this Client instance.
     */
    public function getState()
    {
        return $this->_state;
    }

    /**
     * Gets the URL of the log in form.
     * After login, the browser is redirected to
     * the redirect URL, and a code is passed as a GET parameter to this URL.
     *
     * The browser is also redirected to this URL if the user is already logged
     * in.
     *
     * @param (array) $scopes
     *            - The OneDrive scopes requested by the application.
     *            Supported values: 'wl.signin', 'wl.basic', 'wl.contacts_skydrive',
     *            'wl.skydrive_update'.
     * @param (string) $redirectUri
     *            - The URI to which to redirect to upon
     *            successful log in.
     * @param (array) $options.
     *            Reserved for future use. Default: array(). TODO:
     *            support it.
     * @return (string) The login URL.
     */
    public function getLogInUrl(array $scopes, $redirectUri, array $options = [])
    {
        if (null === $this->_clientId) {
            throw new \Exception('The client ID must be set to call getLoginUrl()');
        }
        
        $imploded = implode(',', $scopes);
        $redirectUri = (string) $redirectUri;
        $this->_state->redirect_uri = $redirectUri;
        
        // When using this URL, the browser will eventually be redirected to the
        // callback URL with a code passed in the URL query string (the name of the
        // variable is "code"). This is suitable for PHP.
        return self::AUTH_URL . '?client_id=' . urlencode($this->_clientId) . '&scope=' . urlencode($imploded) . '&response_type=code' . '&redirect_uri=' . urlencode($redirectUri) . '&display=popup' . '&locale=en';
    }

    /**
     * Gets the access token expiration delay.
     *
     * @return (int) The token expiration delay, in seconds.
     */
    public function getTokenExpire()
    {
        return $this->_state->token->obtained + $this->_state->token->data->expires_in - time();
    }

    /**
     * Gets the status of the current access token.
     *
     * @return (int) The status of the current access token:
     *         0 => no access token
     *         -1 => access token will expire soon (1 minute or less)
     *         -2 => access token is expired
     *         1 => access token is valid
     */
    public function getAccessTokenStatus()
    {
        if (null === $this->_state->token) {
            return 0;
        }
        
        $remaining = $this->getTokenExpire();
        
        if (0 >= $remaining) {
            return - 2;
        }
        
        if (60 >= $remaining) {
            return - 1;
        }
        
        return 1;
    }

    /**
     * Obtains a new access token from OAuth.
     * This token is valid for one hour.
     *
     * @param (string) $clientSecret
     *            - The OneDrive client secret.
     * @param (string) $code
     *            - The code returned by OneDrive after successful log
     *            in.
     * @param (string) $redirectUri.
     *            Must be the same as the redirect URI passed
     *            to getLoginUrl().
     */
    public function obtainAccessToken($clientSecret, $code)
    {
        if (null === $this->_clientId) {
            throw new \Exception('The client ID must be set to call obtainAccessToken()');
        }
        
        if (null === $this->_state->redirect_uri) {
            throw new \Exception('The state\'s redirect URI must be set to call obtainAccessToken()');
        }
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            // General options.
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            
            // SSL options.
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            
            CURLOPT_URL => self::TOKEN_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'client_id=' . urlencode($this->_clientId) . '&redirect_uri=' . urlencode($this->_state->redirect_uri) . '&client_secret=' . urlencode($clientSecret) . '&grant_type=authorization_code' . '&code=' . urlencode($code)
        ]);
        
        $result = curl_exec($curl);
        $decoded = json_decode($result);
        
        if (null === $decoded) {
            throw new \Exception('json_decode() failed');
        }
        
        $this->_state->token = new \stdClass();
        $this->_state->token->obtained = time();
        $this->_state->token->data = (object) $decoded;
    }

    /**
     * refreshAccessToken
     *
     * Gets a new access token when the latter has expired or it's unavailable in session.
     * Refresh token could also be stored in database.Valid for one year.
     * Returns an array:
     * ([token_type],[expires_in],[scope],[access_token],[refresh_token],[authentication_token],[created])
     * See OAuth2 http://msdn.microsoft.com/en-us/library/live/hh243647.aspx
     *
     * @return array $access_token
     *        
     */
    public function refreshAccessToken($clientSecret)
    {
        $parameters = [
            'client_id' => $this->_clientId,
            'redirect_uri' => $this->_state->redirect_uri,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->_state->token->data->refresh_token
        ];
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            // General options.
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            
            // SSL options.
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            
            CURLOPT_URL => self::TOKEN_URL . '?' . http_build_query($parameters)
        ]);
        
        $result = curl_exec($curl);
        $decoded = json_decode($result);
        
        if (null === $decoded) {
            throw new \Exception('json_decode() failed');
        }
        
        $this->_state->token = new \stdClass();
        $this->_state->token->obtained = time();
        $this->_state->token->data = (object) $decoded;
    }

    /**
     * Performs a call to the OneDrive API using the GET method.
     *
     * @param (string) $path
     *            - The path of the API call (eg. me/skydrive).
     */
    public function apiGet($path)
    {
        $url = self::API_URL . $path . '?access_token=' . urlencode($this->_state->token->data->access_token);
        
        $curl = self::_createCurl($path);
        curl_setopt($curl, CURLOPT_URL, $url);
        return $this->_processResult($curl);
    }

    /**
     * Performs a call to the OneDrive API using the POST method.
     *
     * @param (string) $path
     *            - The path of the API call (eg. me/skydrive).
     * @param (array|object) $data
     *            - The data to pass in the body of the request.
     */
    public function apiPost($path, $data)
    {
        $url = self::API_URL . $path;
        $data = (object) $data;
        $curl = self::_createCurl($path);
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json', // The data is sent as JSON as per OneDrive documentation
                'Authorization: Bearer ' . $this->_state->token->data->access_token
            ],
            
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);
        
        return $this->_processResult($curl);
    }

    /**
     * Performs a call to the OneDrive API using the PUT method.
     *
     * @param (string) $path
     *            - The path of the API call (eg. me/skydrive).
     * @param (resource) $stream
     *            - The data stream to upload.
     * @param (string) $contentType
     *            - The MIME type of the data stream, or null
     *            if unknown. Default: null.
     */
    public function apiPut($path, $stream, $contentType = null)
    {
        $curl = self::_createCurl($path);
        $stats = fstat($stream);
        
        $headers = [
            'Authorization: Bearer ' . $this->_state->token->data->access_token
        ];
        
        if (null !== $contentType) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        
        $options = [
            CURLOPT_URL => self::API_URL . $path,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $stream,
            CURLOPT_INFILESIZE => $stats[7]
        ]; // Size
        
        curl_setopt_array($curl, $options);
        return $this->_processResult($curl);
    }

    /**
     * Performs a call to the OneDrive API using the DELETE method.
     *
     * @param (string) $path
     *            - The path of the API call (eg. me/skydrive).
     */
    public function apiDelete($path)
    {
        $url = self::API_URL . $path . '?access_token=' . urlencode($this->_state->token->data->access_token);
        
        $curl = self::_createCurl($path);
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'DELETE'
        ]);
        
        return $this->_processResult($curl);
    }

    /**
     * Performs a call to the OneDrive API using the MOVE method.
     *
     * @param (string) $path
     *            - The path of the API call (eg. me/skydrive).
     * @param (array|object) $data
     *            - The data to pass in the body of the request.
     */
    public function apiMove($path, $data)
    {
        $url = self::API_URL . $path;
        $data = (object) $data;
        $curl = self::_createCurl($path);
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'MOVE',
            
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json', // The data is sent as JSON as per OneDrive documentation
                'Authorization: Bearer ' . $this->_state->token->data->access_token
            ],
            
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);
        
        return $this->_processResult($curl);
    }

    /**
     * Performs a call to the OneDrive API using the COPY method.
     *
     * @param (string) $path
     *            - The path of the API call (eg. me/skydrive).
     * @param (array|object) $data
     *            - The data to pass in the body of the request.
     */
    public function apiCopy($path, $data)
    {
        $curl = self::_createCurl($path);
        
        curl_setopt_array($curl, [
            CURLOPT_URL => self::API_URL . $path,
            CURLOPT_CUSTOMREQUEST => 'COPY',
            
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json', // The data is sent as JSON as per OneDrive documentation
                'Authorization: Bearer ' . $this->_state->token->data->access_token
            ],
            
            CURLOPT_POSTFIELDS => json_encode((object) $data)
        ]);
        
        return $this->_processResult($curl);
    }

    /**
     * Creates a folder in the current OneDrive account.
     *
     * @param (string) $name
     *            - The name of the OneDrive folder to be created.
     * @param (null|string) $parentId
     *            - The ID of the OneDrive folder into which
     *            to create the OneDrive folder, or null to create it in the OneDrive
     *            root folder. Default: null.
     * @param (null|string) $description
     *            - The description of the OneDrive folder to be
     *            created, or null to create it without a description. Default: null.
     * @return (Folder) The folder created, as a Folder instance referencing to
     *         the OneDrive folder created.
     */
    public function createFolder($name, $parentId = null, $description = null)
    {
        if (null === $parentId) {
            $parentId = 'me/skydrive';
        }
        
        $properties = [
            'name' => (string) $name
        ];
        
        if (null !== $description) {
            $properties['description'] = (string) $description;
        }
        
        $folder = $this->apiPost($parentId, (object) $properties);
        return new Folder($this, $folder->id, $folder);
    }

    /**
     * Creates a file in the current OneDrive account.
     *
     * @param (string) $name
     *            - The name of the OneDrive file to be created.
     * @param (resorce) $resource
     *            - Resource of the OneDrive file to be created.
     * @param (null|string) $parentId
     *            - The ID of the OneDrive folder into which
     *            to create the OneDrive file, or null to create it in the OneDrive
     *            root folder. Default: null.
     * @param (null|string) $mimeType
     *            - The MIME type of the data stream, or null
     *            
     * @return (File) The file created, as File instance referencing to the
     *         OneDrive file created.
     *         @throw (\Exception) Thrown on I/O errors.
     */
    public function createFile($name, $resource, $parentId = null, $mimeType = null)
    {
        if (null === $parentId) {
            $parentId = 'me/skydrive';
        }
        
        $file = $this->apiPut($parentId . '/files/' . urlencode($name), $resource, $mimeType);
        return new File($this, $file->id, $file);
    }

    /**
     * Fetches an object from the current OneDrive account.
     *
     * @param
     *            (null|string) The unique ID of the OneDrive object to fetch, or
     *            null to fetch the OneDrive root folder. Default: null.
     * @return (Object) The object fetched, as an Object instance referencing to
     *         the OneDrive object fetched.
     */
    public function fetchObject($objectId = null)
    {
        $objectId = null !== $objectId ? $objectId : 'me/skydrive';
        $result = $this->apiGet($objectId);
        
        if (in_array($result->type, [
            'folder',
            'album'
        ])) {
            return new Folder($this, $result->id, $result);
        }
        
        return new File($this, $result->id, $result);
    }

    /**
     * Fetches the root folder from the current OneDrive account.
     *
     * @return (Folder) The root folder, as a Folder instance referencing to the
     *         OneDrive root folder.
     */
    public function fetchRoot()
    {
        return $this->fetchObject();
    }

    /**
     * Fetches the "Camera Roll" folder from the current OneDrive account.
     *
     * @return (Folder) The "Camera Roll" folder, as a Folder instance referencing
     *         to the OneDrive "Camera Roll" folder.
     */
    public function fetchCameraRoll()
    {
        return $this->fetchObject('me/skydrive/camera_roll');
    }

    /**
     * Fetches the "Documents" folder from the current OneDrive account.
     *
     * @return (Folder) The "Documents" folder, as a Folder instance referencing
     *         to the OneDrive "Documents" folder.
     */
    public function fetchDocs()
    {
        return $this->fetchObject('me/skydrive/my_documents');
    }

    /**
     * Fetches the "Pictures" folder from the current OneDrive account.
     *
     * @return (Folder) The "Pictures" folder, as a Folder instance referencing to
     *         the OneDrive "Pictures" folder.
     */
    public function fetchPics()
    {
        return $this->fetchObject('me/skydrive/my_photos');
    }

    /**
     * Fetches the "Public" folder from the current OneDrive account.
     *
     * @return (Folder) The "Public" folder, as a Folder instance referencing to
     *         the OneDrive "Public" folder.
     */
    public function fetchPublicDocs()
    {
        return $this->fetchObject('me/skydrive/public_documents');
    }

    /**
     * Fetches the properties of an object in the current OneDrive account.
     *
     * @return (array) The properties of the object fetched.
     */
    public function fetchProperties($objectId)
    {
        if (null === $objectId) {
            $objectId = 'me/skydrive';
        }
        
        return $this->apiGet($objectId);
    }

    /**
     * Fetches the objects in a folder in the current OneDrive account.
     *
     * @return (array) The objects in the folder fetched, as Object instances
     *         referencing OneDrive objects.
     */
    public function fetchObjects($objectId)
    {
        if (null === $objectId) {
            $objectId = 'me/skydrive';
        }
        
        $result = $this->apiGet($objectId . '/files');
        $objects = array();
        
        foreach ($result->data as $data) {
            $object = in_array($data->type, [
                'folder',
                'album'
            ]) ? new Folder($this, $data->id, $data) : new File($this, $data->id, $data);
            
            $objects[] = $object;
        }
        
        return $objects;
    }

    /**
     * Updates the properties of an object in the current OneDrive account.
     *
     * @param (string) $objectId
     *            - The unique ID of the object to update.
     * @param (array|object) $properties
     *            - The properties to update. Default:
     *            array().
     *            @throw (\Exception) Thrown on I/O errors.
     */
    public function updateObject($objectId, $properties = array())
    {
        $objectId = (string) $objectId;
        $properties = (object) $properties;
        $encoded = json_encode($properties);
        $stream = fopen('php://memory', 'w+b');
        
        if (false === $stream) {
            throw new \Exception('fopen() failed');
        }
        
        if (false === fwrite($stream, $encoded)) {
            throw new \Exception('fwrite() failed');
        }
        
        if (! rewind($stream)) {
            throw new \Exception('rewind() failed');
        }
        
        $this->apiPut($objectId, $stream, 'application/json');
    }

    /**
     * Moves an object into another folder.
     *
     * @param
     *            (string) The unique ID of the object to move.
     * @param
     *            (null|string) The unique ID of the folder into which to move the
     *            object, or null to move it to the OneDrive root folder. Default:
     *            null.
     */
    public function moveObject($objectId, $destinationId = null)
    {
        if (null === $destinationId) {
            $destinationId = 'me/skydrive';
        }
        
        $this->apiMove($objectId, [
            'destination' => $destinationId
        ]);
    }

    /**
     * Copies a file into another folder.
     * OneDrive does not support copying
     * folders.
     *
     * @param
     *            (string) The unique ID of the file to copy.
     * @param
     *            (null|string) The unique ID of the folder into which to copy the
     *            file, or null to copy it to the OneDrive root folder. Default:
     *            null.
     */
    public function copyFile($objectId, $destinationId = null)
    {
        if (null === $destinationId) {
            $destinationId = 'me/skydrive';
        }
        
        $this->apiCopy($objectId, [
            'destination' => $destinationId
        ]);
    }

    /**
     * Deletes an object in the current OneDrive account.
     *
     * @param (string) $objectId
     *            - The unique ID of the object to delete.
     */
    public function deleteObject($objectId)
    {
        $this->apiDelete((string) $objectId);
    }

    /**
     * Fetches the quota of the current OneDrive account.
     *
     * @return (object) An object with the following properties:
     *         (int) quota - The total space, in bytes.
     *         (int) available - The available space, in bytes.
     */
    public function fetchQuota()
    {
        return $this->apiGet('me/skydrive/quota');
    }

    /**
     * Fetches the recent documents uploaded to the current OneDrive account.
     *
     * @return (object) An object with the following properties:
     *         (array) data - The list of the recent documents uploaded.
     */
    public function fetchRecentDocs()
    {
        return $this->apiGet('me/skydrive/recent_docs');
    }

    /**
     * Fetches the objects shared with the current OneDrive account.
     *
     * @return (object) An object with the following properties:
     *         (array) data - The list of the shared objects.
     */
    public function fetchShared()
    {
        return $this->apiGet('me/skydrive/shared');
    }
}
