VidZapper PHP API
------------------------

VidZapper PHP API Library

`include_once("vidzapper/vidzapper.api.php");


$vidzapper = new VidZapper(array(
  'api'=> 'https://live.vzconsole.com/api/',
	'appId' => 'Your Api Key',
	'secret' => 'Your Api Secret',
  'debug'=> false
)); 
`
examplae to get all navs

`$navs=$vidzapper->v2("library/navs/12/all",'GET', array("\$orderby"=>"ParentID,Sequence"),false);
