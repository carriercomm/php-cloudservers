<?php
require_once('Exception.php');
require_once('Server.php');
require_once('LoadBalancer.php');

/**
 * PHP Cloud implementation for RackSpace (tm)
 *
 * THIS SOFTWARE IS PROVIDED "AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 *
 * IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
 * PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS;
 * OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY,
 * WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE
 * OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
 * EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @author Aleksey Korzun <al.ko@webfoundation.net>
 * @link http://github.com/AlekseyKorzun/php-cloudservers/
 * @link http://www.schematic.com
 * @author Richard Benson <richard.benson@dixcart.com> - For Load Balancers
 * @link http://www.dixcart.com/it
 * @version 0.2
 * @license bsd
 */

class Cloud {
    const METHOD_GET = 0;
    const METHOD_AUTH = 1;
    const METHOD_POST = 2;
    const METHOD_DELETE = 3;
    const METHOD_PUT = 4;

	const RESOURCE_SERVER = 0;
	const RESOURCE_BALANCER = 1;
	const RESOURCE_STORAGE = 2;
	const RESOURCE_CDN = 3;

    private $_apiUser;
    private $_apiKey;
    private $_apiToken;
	private $_apiAccount;
	
	public $servers;
	public $loadBalancers;

    protected $_apiServerUri;
	protected $_apiStorageUri;
	protected $_apiCDNUri;
	protected $_apiLocation;
    protected $_apiAuthUri = array('UK' => 'https://lon.auth.api.rackspacecloud.com/v1.0', 'US' => 'https://auth.api.rackspacecloud.com/v1.0');

	protected $_apiBalancerLocation;
	protected $_apiBalancerUri = array('ORD' => 'https://ord.loadbalancers.api.rackspacecloud.com/v1.0', 'DFW' => 'https://dfw.loadbalancers.api.rackspacecloud.com/v1.0');

	protected $_apiAgent = 'PHP Cloud Server client';

    public $_apiResource;
    public $_apiJson;
    public $_apiResponse;
    public $_apiResponseCode;
	public $_acceptGzip;

    public $_enableDebug = false;

    /**
     * Class constructor
     *
     * @param string $apiId user id that will be used for API
     * @param string $apiKey key that was generated by Rackspace
     * @return null
     */
    function __construct($apiId, $apiKey, $apiLocation = 'US', $apiBalancerLocation = 'ORD', $acceptGzip = true)
    {
        if (!$apiId || !$apiKey) {
            throw new Cloud_Exception('Please provide valid API credentials');
        }

        $this->_apiUser = $apiId;
        $this->_apiKey = $apiKey;
		$this->_apiLocation = $apiLocation;
		$this->_apiBalancerLocation = $apiBalancerLocation;
		$this->_acceptGzip = $acceptGzip;
		
		$this->servers = new Server;
		$this->servers->par = $this;
		$this->balancers = new LoadBalancer;
		$this->balancers->par = $this;
    }

    /**
     * Get authentication token
     *
     * @return mixed return authentication token or false on failure
     */
    public function getToken()
    {
        if (!empty($this->_apiToken)) {
           return $this->_apiToken;
        }

        return false;
    }

    /**
     * Set authentication token
     *
     * @param string $tokenId token you wish to set
     * @return null
     */
    public function setToken($tokenId)
    {
        $this->_apiToken = $tokenId;
    }

    /**
     * Perform authentication
     *
     * @return string returns recieved token
     */
    public function authenticate () {
        $this->_doRequest(self::METHOD_AUTH);
        return $this->_apiToken;
    }

    /**
     * Performs CURL requests (POST,PUT,DELETE,GET) required by API.
     *
     * @param string $method HTTP method that will be used for current request
     * @throws Cloud_Exception
     * @return null
     */
    public function _doRequest($method = self::METHOD_GET, $type = self::RESOURCE_SERVER)
    {
        if (!$this->_apiToken && $method != self::METHOD_AUTH) {
            $this->_doRequest(self::METHOD_AUTH);
        }

        $curl = curl_init();

        $headers = array(
            sprintf("%s: %s", 'X-Auth-Token', $this->_apiToken),
            sprintf("%s: %s", 'Content-Type', 'application/json'));
		
		//Enable GZip encoding
		if ($this->_acceptGzip) curl_setopt($curl, CURLOPT_ENCODING, "gzip");
		
		switch ($type) {
			case self::RESOURCE_BALANCER:
				$strURL = $this->_apiBalancerUri[$this->_apiBalancerLocation].$this->_apiAccount.$this->_apiResource;
				break;
			case self::RESOURCE_STORAGE:
				$strURL = $this->_apiStorageUri.$this->_apiResource;
				break;
			case self::RESOURCE_CDN:
				$strURL = $this->_apiCDNUri.$this->_apiResource;
				break;
			default:
				$strURL = $this->_apiServerUri.$this->_apiResource;
				break;
		}

        switch ($method) {
            case self::METHOD_POST:
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($this->_apiJson));
				if ($this->_enableDebug) echo '<hr>', json_encode($this->_apiJson), '<hr>';
            break;
            case self::METHOD_PUT:
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
                array_push($headers, json_encode($this->_apiJson));
            break;
            case self::METHOD_DELETE:
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
            break;
            case self::METHOD_AUTH:
                $headers = array(
                    sprintf("%s: %s", 'X-Auth-User', $this->_apiUser),
                    sprintf("%s: %s", 'X-Auth-Key', $this->_apiKey));
                $strURL = $this->_apiAuthUri[$this->_apiLocation];
                curl_setopt($curl, CURLOPT_HEADERFUNCTION, array(&$this,'_requestAuth'));
            break;
            default:
                // By default we request data using GET method
                $headers = array(
                    sprintf("%s: %s", 'X-Auth-Token', $this->_apiToken));
            break;
        }

		if ($this->_enableDebug) echo "<hr>url=", $strURL, "<hr>";

		curl_setopt($curl, CURLOPT_URL, $strURL);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_USERAGENT, $this->_apiAgent);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        // If debug is enabled we will output CURL data to screen
        if ($this->_enableDebug) {
			var_dump($headers);
            curl_setopt($curl, CURLOPT_VERBOSE, 1);
			echo("<hr>");
        }

        $this->_apiResponse = curl_exec($curl);

        // Also for debugging purposes output response we got
        if ($this->_enableDebug) {
            var_dump($this->_apiResponse);
        }

        //if (curl_errno($curl) > 0) {
        //    throw new Cloud_Exception('Unable to process this request. curl err: ' . curl_errno($curl));
        //}

        // Retrieve returned HTTP code and throw exceptions when possible
        // error occurs
        $curlInfo = curl_getinfo($curl);
        if (!empty($curlInfo['http_code'])) {
            $this->_apiResponseCode = (int) $curlInfo['http_code'];
            switch ($this->_apiResponseCode) {
                case '401':
                    // User is no longer authorized, re-authenicate with API
                    $this->_doRequest(self::METHOD_AUTH);
                    $this->_doRequest($method, $type);
                break;
                case '400':
					$resp = json_decode($this->_apiResponse, true);
					$err = $resp['badRequest'];
                    throw new Cloud_Exception('Code: ' . $err['code'] . '. Message: ' . $err['message'] . '. Detail: ' . $err['details']);
                break;
                case '404':
                    throw new Cloud_Exception('The server has not found anything matching the Request URI.');
                break;
                case '403':
                    throw new Cloud_Exception('Access is denied for the given request.');
                break;
                case '413':
                    throw new Cloud_Exception('The server is refusing to process a request because the request entity is larger than the server is willing or able to process.');
                break;
                case '500':
                    throw new Cloud_Exception('The server encountered an unexpected condition which prevented it from fulfilling the request.');
                break;
            }
        }
        curl_close($curl);
    }

    /**
     * This method is used for processing authentication response.
     *
     * Basically we retrieve authentication token and server management
     * URI from returned headers.
     *
     * @param mixed $ch instance of curl
     * @param string $header
     * @return int leight of header
     */
    private function _requestAuth($ch, $header)
    {
        if (stripos($header, 'X-Auth-Token') === 0) {
            $this->_apiToken = trim(substr($header, strlen('X-Auth-Token')+1));
        }
        if (stripos($header, 'X-Server-Management-Url') === 0) {
            $this->_apiServerUri = trim(substr($header, strlen('X-Server-Management-Url')+1));
			//Get the account number out
			preg_match('/\/([0-9]+)$/', $this->_apiServerUri, $matches);
			$this->_apiAccount = $matches[0];
        }
        if (stripos($header, 'X-Storage-Url') === 0) {
            $this->_apiStorageUri = trim(substr($header, strlen('X-Storage-Url')+1));
        }
        if (stripos($header, 'X-CDN-Management-Url') === 0) {
            $this->_apiCDNUri = trim(substr($header, strlen('X-CDN-Management-Url')+1));
        }
		

        return strlen($header);
    }

    /**
     * Retrieves current API limits
     *
     * @return mixed json string containing current limits or false on failure
     */
    public function getLimits ()
    {
        $this->_apiResource = '/limits';
        $this->_doRequest();

        if ($this->_apiResponseCode && ($this->_apiResponseCode == '200'
                || $this->_apiResponseCode == '203')) {
            return $this->_apiResponse;
        }

        return false;
    }

    /**
     * Enables debugging output
     *
     * @return null
     */
    public function enableDebug()
    {
        $this->_enableDebug = true;
    }

    /**
     * Disable debugging output
     *
     * @return null
     */
    public function disableDebug()
    {
        $this->_enableDebug = false;
    }

}