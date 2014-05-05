<?php
if (!function_exists('curl_init')) {
  throw new Exception('VidZapper needs the CURL PHP extension.');
}
if (!function_exists('json_decode')) {
    require_once("json.php");
    function json_decode($jsonstring,$watever=null){
        $json = new Services_JSON();
        return $json->decode($jsonstring);
    }
    function json_encode($obj,$watever=null){
        $json = new Services_JSON();
        return $json->encode($obj);
    }
}

/**
 * Thrown when an API call returns an exception.
 *
 * @author Anuj Pandey <a@e10.in>
 */
class VidZapperApiException extends Exception
{
  /**
   * The result from the API server that represents the exception information.
   */
  protected $result;

  /**
   * Make a new API Exception with the given result.
   *
   * @param Array $result the result from the API server
   */
  public function __construct($result) {
    $this->result = $result;

    $code = isset($result['error_code']) ? $result['error_code'] : 0;

    if (isset($result['error_description'])) {
      // OAuth 2.0 Draft 10 style
      $msg = $result['error_description'];
    } else if (isset($result['error']) && is_array($result['error'])) {
      // OAuth 2.0 Draft 00 style
      $msg = $result['error']['message'];
    } else if (isset($result['error_msg'])) {
      // Rest server style
      $msg = $result['error_msg'];
    } else {
      $msg = 'Unknown Error. Check getResult()';
    }

    parent::__construct($msg, $code);
  }

  /**
   * Return the associated result object returned by the API server.
   *
   * @returns Array the result from the API server
   */
  public function getResult() {
    return $this->result;
  }

  /**
   * Returns the associated type for the error. This will default to
   * 'Exception' when a type is not available.
   *
   * @return String
   */
  public function getType() {
    if (isset($this->result['error'])) {
      $error = $this->result['error'];
      if (is_string($error)) {
        // OAuth 2.0 Draft 10 style
        return $error;
      } else if (is_array($error)) {
        // OAuth 2.0 Draft 00 style
        if (isset($error['type'])) {
          return $error['type'];
        }
      }
    }
    return 'Exception';
  }

  /**
   * To make debugging easier.
   *
   * @returns String the string representation of the error
   */
  public function __toString() {
    $str = $this->getType() . ': ';
    if ($this->code != 0) {
      $str .= $this->code . ': ';
    }
    return $str . $this->message;
  }
}

class ApiKey
{
    protected $ValidTill;
    protected $Parameters;
    protected $Key;
    protected $Method;
    protected $Secret;
    protected $Gap;

    protected function getCurrentTimeStamp(){
        date_default_timezone_set('UTC'); 
        $dt=gmstrftime(time()+$this->Gap);
        return strftime("%Y%m%d%H%M",$dt);
    }
    
    public function getURL($method,$parameters){
        $this->Method=$method;
        $this->Parameters=urldecode($parameters);
        $this->ValidTill=$this->getCurrentTimeStamp();

        $appKey='{"ValidTill":"'.$this->ValidTill.'","Parameters":"'.$this->Parameters.'","Key":"'.$this->Key.'","Method":"'.$this->Method.'"}';
        $rat=strpos($this->Secret,"r")+1;
        $url=substr($this->Secret, 0, $rat).hash_hmac('sha1', utf8_encode($appKey),utf8_encode($this->Secret)).'/'.$method.$parameters;
        VidZapper::debugLog($appKey);
        return $url;
    }

    public function __construct($key,$secret,$gap) {
        $this->Key = $key;
        $this->Secret = $secret;
        $this->Gap=$gap;
    }
}

/**
 * Provides access to the VidZapper Platform.
 *
 * @author Anuj Pandey <a@e10.in>
 */
class VidZapper
{
  /**
   * Version.
   */
  const VERSION = '1.0.1';
  const COOKIE_NAME="vzuser";

  /**
   * Default options for curl.
   */
  public static $CURL_OPTS = array(
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => array('Accept: application/json'),
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_POST => 0,
    CURLOPT_USERAGENT      => 'VidZapper-php-1.0',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0
  );

  /**
   * List of query parameters that get automatically dropped when rebuilding
   * the current URL.
   */
  protected static $DROP_QUERY_PARAMS = array();

  /**
   * Maps aliases to VidZapper domains.
   */
  public static $DOMAIN_MAP = array(
    'api'      => 'https://vzconsole.com/live/api/',
    'feed'      => 'https://vzconsole.com/live/feed/'
  );

  public static $DEBUG = false;
  public static $CacheTimeout = 15;

  protected $keyGen;
  /**
   * The Application ID.
   */
  protected $appId;

  /**
   * The Application API Secret.
   */
  protected $apiSecret;

  /**
   * Last Raw Result.
   */
  protected $rawResult;

  /**
   * Base domain for the Cookie.
   */
  protected $baseDomain = '';

  /**
   * Indicates if the CURL based @ syntax for file uploads is enabled.
   */
  protected $fileUploadSupport = false;

  /**
   * Initialize a VidZapper Application.
   *
   * The configuration:
   * - appId: the application ID
   * - secret: the application secret
   * - domain: (optional) domain for the cookie
   * - fileUpload: (optional) boolean indicating if file uploads are enabled
   *
   * @param Array $config the application configuration
   */
  public function __construct($config) {
    $this->setAppId($config['appId']);
    $this->setApiSecret($config['secret']);
    $gp=0;
    if(isset($config['gap'])){$gp=$config['gap'];}
    if(isset($config['debug'])){self::$DEBUG=true;}
    if(isset($config['cache'])){self::$CacheTimeout= intval($config['cache']);}
    if(isset($config['api'])){self::$DOMAIN_MAP['api']=$config['api'];}
    if(isset($config['feed'])){self::$DOMAIN_MAP['feed']=$config['feed'];}
    if(isset($config['domain'])) {$this->setBaseDomain($config['domain']);}
    if(isset($config['fileUpload'])) {$this->setFileUploadSupport($config['fileUpload']);}
    $this->keyGen=new ApiKey($this->appId,$this->apiSecret,$gp);
  }

  public function getLastRawResult() {
    return $this->rawResult;
  }

  /**
   * Set the Application ID.
   *
   * @param String $appId the Application ID
   */
  public function setAppId($appId) {
    $this->appId = $appId;
    return $this;
  }

  /**
   * Get the Application ID.
   *
   * @return String the Application ID
   */
  public function getAppId() {
    return $this->appId;
  }

  /**
   * Set the API Secret.
   *
   * @param String $appId the API Secret
   */
  public function setApiSecret($apiSecret) {
    $this->apiSecret = $apiSecret;
    return $this;
  }

  /**
   * Get the API Secret.
   *
   * @return String the API Secret
   */
  public function getApiSecret() {
    return $this->apiSecret;
  }

  /**
   * Set the base domain for the Cookie.
   *
   * @param String $domain the base domain
   */
  public function setBaseDomain($domain) {
    $this->baseDomain = $domain;
    return $this;
  }

  /**
   * Get the base domain for the Cookie.
   *
   * @return String the base domain
   */
  public function getBaseDomain() {
    return $this->baseDomain;
  }

  /**
   * Set the file upload support status.
   *
   * @param String $domain the base domain
   */
  public function setFileUploadSupport($fileUploadSupport) {
    $this->fileUploadSupport = $fileUploadSupport;
    return $this;
  }

  /**
   * Get the file upload support status.
   *
   * @return String the base domain
   */
  public function useFileUploadSupport() {
    return $this->fileUploadSupport;
  }

  public function getViewerID(){
    return $_COOKIE[self::COOKIE_NAME];
  }
  protected function createVisitor(){
    $visitor = $this->api('Visitor',array("UserName"=>uniqid()));
    $timeout = time() + 60 * 60 * 24 * 90;
    setcookie(self::COOKIE_NAME, $visitor->UserName,$timeout);
    return $visitor->UserName;
  }
  public function makeAnonVisitor(){
      //echo $_COOKIE[self::COOKIE_NAME];
      if(!isset($_COOKIE[self::COOKIE_NAME])){ 
        return $this->createVisitor();
      }
      if(!is_null($_COOKIE[self::COOKIE_NAME])){
        return $_COOKIE[self::COOKIE_NAME];
      }else{
        return "jumper"; /*couldn't find cookie value*/
      }
  }

  /**
   * Invoke the old restserver.php endpoint.
   *
   * @param Array $params method call object
   * @return the decoded response object
   * @throws FacebookApiException
   */
  public function api(/* normal  ?*/) {
    // generic application level parameters
    $args = func_get_args();
    if (is_array($args[0])) {
        return $this->_api($args[0],array());
    }else{
        if(sizeof($args)>1){
            return $this->_api($args[0],$args[1],array());
        }
    }
  }

  protected function _api($method,$id,$params) {
    
    if(is_array($id)){
        $params=$id;
        $id=0;
    }

    foreach ($params as $key => $value) {
      if (!is_string($value)) {
        $params[$key] = json_encode($value);
        self::debugLog("adding param $key = [$value]");
      }
    }

    if($id!=0){$method.="/".$id;}
    $method=utf8_decode($method);

    $this->rawResult=$this->makeRequest($this->getUrl($method,$params),$params);
    self::debugLog($this->rawResult);
    $result = json_decode($this->rawResult);

    // results are returned, errors are thrown
    if (is_array($result) && (isset($result['error_code'])|| $result['Error']==true)) {
      throw new VidZapperApiException($result);
    }
    return $result;
  }

  /**
   * Makes an HTTP request. This method can be overriden by subclasses if
   * developers want to do fancier things or use something other than curl to
   * make the request.
   *
   * @param String $url the URL to make the request to
   * @param Array $params the parameters to use for the POST body
   * @param CurlHandler $ch optional initialized curl handle
   * @return String the response text
   */
  protected function makeRequest($url, $params, $ch=null) {
    if (!$ch) {$ch = curl_init();}
    $opts = self::$CURL_OPTS;
    if ($this->useFileUploadSupport()) {
      $opts[CURLOPT_POSTFIELDS] = $params;
    } else {
      if(!empty($params)){
        //$opts[CURLOPT_POSTFIELDS] = http_build_query($params, null, '&');
        //self::debugLog('Posting -> ['.$opts[CURLOPT_POSTFIELDS].']');
      }
    }
    $opts[CURLOPT_URL] = $url;
    self::debugLog('<a target="_blank" href="'.$url.'">'.$url.'</a>');
    // disable the 'Expect: 100-continue' behaviour. This causes CURL to wait
    // for 2 seconds if the server does not support this header.
    if (isset($opts[CURLOPT_HTTPHEADER])) {
      $existing_headers = $opts[CURLOPT_HTTPHEADER];
      $existing_headers[] = 'Expect:';
      $opts[CURLOPT_HTTPHEADER] = $existing_headers;
    } else {
      $opts[CURLOPT_HTTPHEADER] = array('Expect:');
    }
    self::debugLog('Header -> '.json_encode($opts[CURLOPT_HTTPHEADER]).'');
    curl_setopt_array($ch, $opts);
    $result = curl_exec($ch);
    if (curl_errno($ch) == 60) { // CURLE_SSL_CACERT
      self::errorLog('Invalid or no certificate authority found, using bundled information');
      curl_setopt($ch, CURLOPT_CAINFO,
                  dirname(__FILE__) . '/vz_ca_chain_bundle.crt');
      $result = curl_exec($ch);
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($result === false) {
        $result='{"Error":true,"StatusCode":'.curl_errno($ch).',"Message":"'.curl_error($ch).'","Raw":'.$result.'}';
    }else if($httpCode>=400 && $httpCode<600){
        $result='{"Error":true,"StatusCode":'.$httpCode.',"Message":"'.StatusCodes::getMessageForCode($httpCode).'","Raw":'.$result.'}';
        //ignore 400 error, and let the validation errors pass through;
    }else if($httpCode<200 || $httpCode>=300){
        $result='{"Error":true,"StatusCode":'.httpCode.',"Message":"'.StatusCodes::getMessageForCode($httpCode).'","Raw":'.$result.'}';
    }
    curl_close($ch);
    return $result;
  }
  
  /**
   * Build the URL for given domain alias, path and parameters.
   *
   * @param $name String the name of the domain
   * @param $path String optional path (without a leading slash)
   * @param $params Array optional query parameters
   * @return String the URL for the given parameters
   */
  protected function getUrl($method, $params=array()) {
    $url = self::$DOMAIN_MAP['api'];
    $query='';
    if (!empty($params)) {
        $query = '?' .http_build_query($params, null, '&');
    }
    self::debugLog($query);
    return $url.$this->keyGen->getUrl($method,$query);
  }

  /**
   * Returns the Current URL, stripping it of known VZ parameters that should
   * not persist.
   *
   * @return String the current URL
   */
   
  protected function makeRequestURI($protocol){
    if (!isset($_SERVER['REQUEST_URI'])) 
    {
        // Set the SCRIPT_FILENAME variable, as it is broken under IIS too
        $_SERVER['SCRIPT_FILENAME'] = $_SERVER['SCRIPT_NAME'];
        
        // Running PHP in ISAPI mode?  Use the following line:
        //$_SERVER['REQUEST_URI']=$_SERVER['URL'];
        // Running PHP in CGI Mode?  Use this line:
        $_SERVER['REQUEST_URI']=$_SERVER['ORIG_PATH_INFO'];
        if (isset($_SERVER['QUERY_STRING']))
        {
            // IIS6 sets the QUERY_STRING variable, even if it contains nothing.
            if (strlen($_SERVER['QUERY_STRING'] > 0))
            {
                // If we've made it this far, QUERY_STRING contains some data
                $_SERVER['REQUEST_URI'] .= '?' . $_SERVER['QUERY_STRING'];
            }
        }
        
    }
    return  $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
  }
  
  protected function getCurrentUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on'
      ? 'https://'
      : 'http://';
    $currentUrl = $this->makeRequestURI($protocol);;
    $parts = parse_url($currentUrl);

    // drop known fb params
    $query = '';
    if (!empty($parts['query'])) {
      $params = array();
      parse_str($parts['query'], $params);
      foreach(self::$DROP_QUERY_PARAMS as $key) {
        unset($params[$key]);
      }
      if (!empty($params)) {
        $query = '?' . http_build_query($params, null, '&');
      }
    }

    // use port if non default
    $port =
      isset($parts['port']) &&
      (($protocol === 'http://' && $parts['port'] !== 80) ||
       ($protocol === 'https://' && $parts['port'] !== 443))
      ? ':' . $parts['port'] : '';

    // rebuild
    return $protocol . $parts['host'] . $port . $parts['path'] . $query;
  }


  /**
   * Prints to the error log if you aren't in command line mode.
   *
   * @param String log message
   */
  protected static function errorLog($msg) {
    // disable error log if we are running in a CLI environment
    // @codeCoverageIgnoreStart
    if (php_sapi_name() != 'cli') {
      error_log($msg);
    }
    // uncomment this if you want to see the errors on the page
    // print 'error_log: '.$msg."\n";
    // @codeCoverageIgnoreEnd
  }

  public static function debugLog($msg) {
    // disable error log if we are running in a CLI environment
    // @codeCoverageIgnoreStart
    if (VidZapper::$DEBUG==true) {
        date_default_timezone_set('UTC'); 
        $dt=gmstrftime(time());
        echo strftime("%Y%m%d%H%M",$dt)." = ".$msg."<br />";
    }
    // uncomment this if you want to see the errors on the page
    // print 'error_log: '.$msg."\n";
    // @codeCoverageIgnoreEnd
  }

  /**
   * Base64 encoding that doesn't need to be urlencode()ed.
   * Exactly the same as base64_encode except it uses
   *   - instead of +
   *   _ instead of /
   *
   * @param String base64UrlEncodeded string
   */
  protected static function base64UrlDecode($input) {
    return base64_decode(strtr($input, '-_', '+/'));
  }

    function writeObjectCache($cachefile,$cacheContent){
        if(!!!$cacheContent && $cacheContent->Error=="true") return;
        $fp = fopen($cachefile,'w');
        fwrite($fp,$cacheContent);
        fclose($fp); 
        //echo "cache created for file : $cachefile = ".dirname($cachefile);
    }

    function getCacheFileName($methodname,$parameters){
        return $_SERVER['DOCUMENT_ROOT']."/cache/".hash_hmac('sha1',json_encode(array($methodname,$parameters)),"cacheSecret").".json"; /*dont need this to change since Its just to make a file*/
    }

    function fetch($methodname,$parameters=array(),$raw=false,$cached=true,$xml=false){
        if($xml==true){
            return $this->fetchCore(array('Accept: application/xml','Accept-Charset: utf-8;'),$methodname,$parameters,$raw,$cached);
        }else{
            return $this->fetchCore(array('Accept: application/json','Accept-Charset: utf-8;'),$methodname,$parameters,$raw,$cached);
        }
    }

    function fetchCore($headers,$methodname,$parameters=array(),$raw=false,$cached=true){
        VidZapper::$CURL_OPTS[CURLOPT_HTTPHEADER]=$headers;
        $expires = (VidZapper::$CacheTimeout * 60); /*15 Minutes*/
        $cachetime = time()-$expires; /*5 Minutes*/
        $cachefile=$this->getCacheFileName($methodname,$parameters); /*get cache file name*/
        $data=NULL;

        if($cached &&!isset($_REQUEST["nocache"])){
            header("Pragma: public");
            header("Cache-Control: maxage=".$expires);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time()+$expires) . ' GMT');
            if(file_exists($cachefile) && ($cachetime < filemtime($cachefile)) && filesize($cachefile)>5){
		        //echo "cached";
	            $fp = fopen($cachefile,'r');
	            $data = fread($fp, filesize($cachefile));fclose($fp); 
	            if(!$raw) return json_decode($data);
            }
        }
        if(!isset($data)){
	        $result=$this->api($methodname,$parameters);
	        $data=$this->getLastRawResult();
	        $this->writeObjectCache($cachefile,$data);
            if(!$raw) return $result;
        }
        return $data;
    }

    function pushJSON($obj){
        $this->flushJSON(json_encode($obj));
    }

    function flush($content,$xml=false){
        header($xml ? 'Content-type: application/xml; charset=utf-8' : 'Content-type: application/json; charset=utf-8');
        echo $content;
    }

    function flushJSON($content){
        $this->flush($content);
    }

    function pushScript($obj,$varName){
        $this->flushScript(json_encode($obj),$varName);
    }

    function flushScript($objData,$varName){
    ?>
    <script type="text/javascript" charset="utf-8">
	    <?php echo $varName;?> = <?php echo $objData ?>;
    </script>
    <?php
    }

}

class StatusCodes {
	// [Informational 1xx]
	const HTTP_CONTINUE = 100;
	const HTTP_SWITCHING_PROTOCOLS = 101;
	// [Successful 2xx]
	const HTTP_OK = 200;
	const HTTP_CREATED = 201;
	const HTTP_ACCEPTED = 202;
	const HTTP_NONAUTHORITATIVE_INFORMATION = 203;
	const HTTP_NO_CONTENT = 204;
	const HTTP_RESET_CONTENT = 205;
	const HTTP_PARTIAL_CONTENT = 206;
	// [Redirection 3xx]
	const HTTP_MULTIPLE_CHOICES = 300;
	const HTTP_MOVED_PERMANENTLY = 301;
	const HTTP_FOUND = 302;
	const HTTP_SEE_OTHER = 303;
	const HTTP_NOT_MODIFIED = 304;
	const HTTP_USE_PROXY = 305;
	const HTTP_UNUSED= 306;
	const HTTP_TEMPORARY_REDIRECT = 307;
	// [Client Error 4xx]
	const errorCodesBeginAt = 400;
	const HTTP_BAD_REQUEST = 400;
	const HTTP_UNAUTHORIZED  = 401;
	const HTTP_PAYMENT_REQUIRED = 402;
	const HTTP_FORBIDDEN = 403;
	const HTTP_NOT_FOUND = 404;
	const HTTP_METHOD_NOT_ALLOWED = 405;
	const HTTP_NOT_ACCEPTABLE = 406;
	const HTTP_PROXY_AUTHENTICATION_REQUIRED = 407;
	const HTTP_REQUEST_TIMEOUT = 408;
	const HTTP_CONFLICT = 409;
	const HTTP_GONE = 410;
	const HTTP_LENGTH_REQUIRED = 411;
	const HTTP_PRECONDITION_FAILED = 412;
	const HTTP_REQUEST_ENTITY_TOO_LARGE = 413;
	const HTTP_REQUEST_URI_TOO_LONG = 414;
	const HTTP_UNSUPPORTED_MEDIA_TYPE = 415;
	const HTTP_REQUESTED_RANGE_NOT_SATISFIABLE = 416;
	const HTTP_EXPECTATION_FAILED = 417;
	// [Server Error 5xx]
	const HTTP_INTERNAL_SERVER_ERROR = 500;
	const HTTP_NOT_IMPLEMENTED = 501;
	const HTTP_BAD_GATEWAY = 502;
	const HTTP_SERVICE_UNAVAILABLE = 503;
	const HTTP_GATEWAY_TIMEOUT = 504;
	const HTTP_VERSION_NOT_SUPPORTED = 505;
		
	private static $messages = array(
		// [Informational 1xx]
		100=>'100 Continue',
		101=>'101 Switching Protocols',
		// [Successful 2xx]
		200=>'200 OK',
		201=>'201 Created',
		202=>'202 Accepted',
		203=>'203 Non-Authoritative Information',
		204=>'204 No Content',
		205=>'205 Reset Content',
		206=>'206 Partial Content',
		// [Redirection 3xx]
		300=>'300 Multiple Choices',
		301=>'301 Moved Permanently',
		302=>'302 Found',
		303=>'303 See Other',
		304=>'304 Not Modified',
		305=>'305 Use Proxy',
		306=>'306 (Unused)',
		307=>'307 Temporary Redirect',
		// [Client Error 4xx]
		400=>'400 Bad Request',
		401=>'401 Unauthorized',
		402=>'402 Payment Required',
		403=>'403 Forbidden',
		404=>'404 Not Found',
		405=>'405 Method Not Allowed',
		406=>'406 Not Acceptable',
		407=>'407 Proxy Authentication Required',
		408=>'408 Request Timeout',
		409=>'409 Conflict',
		410=>'410 Gone',
		411=>'411 Length Required',
		412=>'412 Precondition Failed',
		413=>'413 Request Entity Too Large',
		414=>'414 Request-URI Too Long',
		415=>'415 Unsupported Media Type',
		416=>'416 Requested Range Not Satisfiable',
		417=>'417 Expectation Failed',
		// [Server Error 5xx]
		500=>'500 Internal Server Error',
		501=>'501 Not Implemented',
		502=>'502 Bad Gateway',
		503=>'503 Service Unavailable',
		504=>'504 Gateway Timeout',
		505=>'505 HTTP Version Not Supported'
	);
    public static function httpHeaderFor($code) {
		return 'HTTP/1.1 ' . self::$messages[$code];
	}
    public static function getMessageForCode($code) {
		return self::$messages[$code];
	}
}

?>
