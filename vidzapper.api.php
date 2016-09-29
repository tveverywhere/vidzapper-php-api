<?php

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
    
    public function getURL($method,$v2,$parameters,$encrypt=true){
        $this->Method=$method;
        $this->Parameters=urldecode($parameters);
        $this->ValidTill=$this->getCurrentTimeStamp();

        $appKey='{"ValidTill":"'.$this->ValidTill.'","Parameters":"'.$this->Parameters.'","Key":"'.$this->Key.'","Method":"'.$this->Method.'"}';
        $rat=strpos($this->Secret,"r")+1;
        if($encrypt==true){
            $url=substr($this->Secret, 0, $rat).hash_hmac('sha1', utf8_encode($appKey),utf8_encode($this->Secret)).'/'.$method.$parameters;
        }else{
            $url=$method.$parameters;
        }
        return $v2.$url;
    }

    public function __construct($key,$secret,$gap) {
        $this->Key = $key;
        $this->Secret = $secret;
        $this->Gap=$gap;
    }
}
class VidZapperApiException extends Exception
{
  protected $result;
  public function __construct($result) {
    $this->result = $result;
    parent::__construct($result->Url."->".$result->Message, $code);
  }
  public function getResult() {
    return $this->result;
  }
  public function getType() {
    return 'Exception';
  }
  public function __toString() {
    $str = $this->getType() . ': ';
    if ($this->code != 0) {
      $str .= $this->code . ': ';
    }
    return $str . $this->message;
  }
}
function generateCallTrace($e)
{
    $trace = explode("\n", $e->getTraceAsString());
    // reverse array to make steps line up chronologically
    $trace = array_reverse($trace);
    array_shift($trace); // remove {main}
    array_pop($trace); // remove call to this method
    $length = count($trace);
    $result = array();
    
    for ($i = 0; $i < $length; $i++)
    {
        $result[] = ($i + 1)  . ')' . substr($trace[$i], strpos($trace[$i], ' ')); // replace '#someNum' with '$i)', set the right ordering
    }
    
    return $e."\n\t" . implode("\n\t", $result);
}
class VidZapper
{
	public static $CURL_OPTS = array(
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => array('Accept: application/json'),
		CURLOPT_TIMEOUT        => 60,
		CURLOPT_POST => 0,
		CURLOPT_USERAGENT      => 'VidZapper-php-2.0',
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => 0
	);

	public static $DEBUG = true;
    public static $CACHE;

	protected $keyGen;
	protected $apiUrl;
	protected $appId;
	protected $apiSecret;

	public function __construct($config) {
		$this->appId=$config['appId'];
		$this->apiSecret=$config['secret'];
		$this->apiUrl=$config['api'];
		$this->keyGen=new ApiKey($this->appId,$this->apiSecret,0);
		if(isset($config['debug'])){self::$DEBUG=$config['debug'];}
	}

    public static function ApplyCaching($cache){
        VidZapper::$CACHE=$cache;
    }

	public static function debugLog($msg) {
		if (VidZapper::$DEBUG==true) {
			date_default_timezone_set('UTC'); 
			$dt=gmstrftime(time());
			echo $dt." = ".$msg."<br />";
		}
	}
	
	public function api(/* normal  ?*/) {
		// generic application level parameters
		$args = func_get_args();
		if (is_array($args[0])) {
			return $this->_api($args[0],array(),$args[1]);
		}else{
			if(sizeof($args)>1){
				return $this->_api($args[0],$args[1],array(),$args[2],$args[3]);
			}
		}
	}
	protected function _api($method,$id,$params,$custom,$v2='') {

		if(is_array($id)){
			$params=$id;
			$id=0;
		}

		foreach ($params as $key => $value) {
			if (!is_string($value)) {
				$params[$key] = json_encode($value);
				//self::debugLog("adding param $key = [$value]");
			}
		}

		if($id!=0){$method.="/".$id;}
		$method=utf8_decode($method);

		$url=$this->getUrl($method,$v2,$params);
		$seg=parse_url($url);
		$elem=explode('/',$seg["path"]);
		$cacheKey=join("_",array_splice($elem,(0-count($elem))+4))."_".str_replace("&","_",urldecode($seg["query"]));
		$cacheKey=str_replace("$","",$cacheKey);
		$cacheKey=str_replace(" ","+",$cacheKey);
		$cacheKey=str_replace("_inlinecount=allpages","",$cacheKey);

		$this->rawResult=$this->makeRequest($url,$params, $params["ispost"] ? 'POST':$custom,$cacheKey);
		
        self::debugLog("<code>".$this->rawResult."</code>");

		$result = json_decode($this->rawResult);

		// results are returned, errors are thrown
		if (is_array($result) && (isset($result['error_code'])|| $result['Error']==true)) {
			echo generateCallTrace(new VidZapperApiException($result));
		}
		if (is_object($result) && (isset($result->error_code)|| $result->Error)) {
			echo generateCallTrace(new VidZapperApiException($result));
		}

		return $result;

	}
	protected function makeRequest($url, $params,$custom,$cacheKey, $ch=null) {
		if (!$ch) {$ch = curl_init();}
		$opts = self::$CURL_OPTS;
		if(!empty($params)){
			if($params["ispost"]==true || $custom=='POST'){
				$opts[CURLOPT_POSTFIELDS] = http_build_query($params, null, '&');
				self::debugLog('Posting -> ['.$opts[CURLOPT_POSTFIELDS].']');
			}
		}
		if($custom!='' && $custom == "DELETE"){
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $custom);
		}else if($custom!='' && $custom == "PUT")
		{
			curl_setopt($ch, CURLOPT_PUT, true);
		}

		$opts[CURLOPT_URL] = $url;
		if (isset($opts[CURLOPT_HTTPHEADER])) {
			$existing_headers = $opts[CURLOPT_HTTPHEADER];
			$existing_headers[] = 'Expect:';
			$opts[CURLOPT_HTTPHEADER] = $existing_headers;
		} else {
			$opts[CURLOPT_HTTPHEADER] = array('Expect:');
		}
		curl_setopt_array($ch, $opts);

        if(isset(VidZapper::$CACHE)){
             if($custom=='GET'){
				$tags=explode('_',$cacheKey);
                $result=VidZapper::$CACHE->get($cacheKey);//try again if the last attempt failed.
                if(strlen($result)>0 && !strrpos($result,"An error has occurred.")) {
					self::debugLog('Cache <a target="_blank" href="'.urldecode($url).'">'.urldecode($url).'</a>');
                    return $result;
				}
            }
        }

		$result = curl_exec($ch);
		self::debugLog('Api <a target="_blank" href="'.urldecode($url).'">'.urldecode($url).'</a>');
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		VidZapper::$CACHE->set($cacheKey, $result, 3600*24*365,array("tags"=>array()));
		if ($result === false) {
			$result='{"Url":"'.$url.'", "Data":"'.json_encode($params).'", "Error":true,"StatusCode":'.curl_errno($ch).',"Message":"'.curl_error($ch).'","Raw":'.$result.'}';
		}else if($httpCode>=400 && $httpCode<600){
            $err=json_decode($result);
			$result='{"Url":"'.$url.'", "Data":"'.json_encode($params).'","Error":true,"StatusCode":'.$httpCode.',"Message":"'.(isset($err->Message) ? $err->Message : $err->ExceptionMessage).'","Raw":'.$result.'}';
			//ignore 400 error, and let the validation errors pass through;
		}else if($httpCode<200 || $httpCode>=300){
			$result='{"Url":"'.$url.'", "Data":"'.json_encode($params).'","Error":true,"StatusCode":'.httpCode.',"Message":"'.StatusCodes::getMessageForCode($httpCode).'","Raw":'.$result.'}';
		}
		curl_close($ch);
		return $result;
	}
	protected function getUrl($method,$v2, $params=array(),$encrypt=true) {
		$url = $this->apiUrl;
		$query='';
		if (!empty($params)) {
			$query = '?' .http_build_query($params, null, '&');
		}
		return $url.$this->keyGen->getUrl($method,$v2,$query,$encrypt);
	}
	public function post($methodname,$parameters=array(),$raw=false,$cached=false,$xml=false){
		$parameters["ispost"]=true;
		return $this->fetch($methodname,$parameters,$raw,$cached,$xml);      
	}
	public function v2($methodname,$custom='GET',$parameters=array()){
		return $this->fetchCore(array('Accept: application/json','Accept-Charset: utf-8;'),$methodname,$parameters,$custom,$v2 = 'v2/');
	}
	public function get($methodname,$parameters=array()){
		return $this->fetchCore(array('Accept: application/json','Accept-Charset: utf-8;'),$methodname,$parameters,"GET",$v2 = 'v2/');
	}
	public function fetch($methodname,$parameters=array()){
		return $this->fetchCore(array('Accept: application/json','Accept-Charset: utf-8;'),$methodname,'GET',$parameters);
	}
	public function delete($methodname,$parameters=array(),$raw=false,$cached=false,$xml=false){
		return $this->fetch($methodname,$parameters,$raw,$cached,$xml,"DELETE");
	}
	public function put($methodname,$parameters=array(),$raw=false,$cached=false,$xml=false){
		return $this->fetch($methodname,$parameters,$raw,$cached,$xml,"PUT");
	}
	public function fetchCore($headers,$methodname,$parameters=array(),$custom='GET',$v2=''){
		VidZapper::$CURL_OPTS[CURLOPT_HTTPHEADER]=$headers;
		return $this->api($methodname,$parameters,$custom,$v2);
	}
	public function flush($content,$xml=false){
		header($xml ? 'Content-type: application/xml; charset=utf-8' : 'Content-type: application/json; charset=utf-8');
		echo $content;
	}
	public function flushJSON($content){
		$this->flush($content);
	}
	public function pushScript($obj,$varName){
		$this->flushScript(json_encode($obj),$varName);
	}
	public function flushScript($objData,$varName){
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
