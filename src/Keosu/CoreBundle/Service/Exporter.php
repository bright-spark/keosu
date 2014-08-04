<?php
/************************************************************************
 Keosu is an open source CMS for mobile app
Copyright (C) 2014  Vincent Le Borgne, Pockeit

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as
published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <http://www.gnu.org/licenses,.
************************************************************************/
namespace Keosu\CoreBundle\Service;

use Keosu\CoreBundle\KeosuEvents;
use Keosu\CoreBundle\KeosuExtension;

use Keosu\CoreBundle\Util\ZipUtil;
use Keosu\CoreBundle\Util\ThemeUtil;
use Keosu\CoreBundle\Util\FilesUtil;
use Keosu\CoreBundle\Util\StringUtil;
use Keosu\CoreBundle\Util\TemplateUtil;

use Keosu\CoreBundle\Entity\Page;
use Keosu\CoreBundle\Entity\Package;

use Keosu\CoreBundle\Event\ExportPackageEvent;

use Keosu\CoreBundle\Model\ZoneModel;

use Symfony\Component\DomCrawler\Crawler;

class Exporter {

	const EXPORT_WEB_PATH = '/web/keosu/export/';
	
	/**
	 * Return image dir
	 * @param string $app name for app
	 * @return string
	 */
	public static function getImageDir($app) {
		return __DIR__ . '/../../../../web/keosu/res/'.$app.'/';
	}
	
	/**
	 * Return dir where to export app
	 */
	public static function getExportAbsolutePath() {
		return __DIR__ . '/../../../../web/keosu/export/'; //TODO edit this path
	}

	private $doctrine;

	private $container;

	private $packageManager;

	public function __construct($doctrine,$container) {
		$this->doctrine = $doctrine;
		$this->container = $container;
		$this->packageManager = $this->container->get('keosu_core.packagemanager');
	}

	public function exportApp() {
		$this->export($this->container->get('keosu_core.curapp')->getCurApp());
	}
	
	public function export($appId) {

		$em = $this->doctrine->getManager();
		$baseurl = $this->container->getParameter('url_base');

		$pages = $em->getRepository('KeosuCoreBundle:Page')->findByAppId($appId);

		$this->cleanDir();

		//Export theme
		$app = $em->getRepository('KeosuCoreBundle:App')->find($appId);
		$json = json_encode(array('name' => $app->getName()));
		
		FilesUtil::copyContent($json, $this::getExportAbsolutePath() . '/simulator/www/data/appName.json');
		
		FilesUtil::copyFolder(ThemeUtil::getAbsolutePath() . $app->getTheme().'/style',
				$this::getExportAbsolutePath() . '/simulator/www/theme');

		//cordova_plugins.json
		copy(TemplateUtil::getAbsolutePath() . '/main-header/cordova_plugins.js',
			$this::getExportAbsolutePath() . '/simulator/www/cordova_plugins.js');
		
		copy(TemplateUtil::getAbsolutePath() . '/main-header/index.html',
			$this::getExportAbsolutePath() . '/simulator/www/index.html');

		
		//Copy all theme/header/js dir to web/export/www/js
		FilesUtil::copyFolder(ThemeUtil::getAbsolutePath() . $app->getTheme().'/header/js',
			$this::getExportAbsolutePath() . '/simulator/www/js');
			
		//Copy Splashcreens and icons
		FilesUtil::copyFolder($this::getImageDir($app->getId()).'/', $this::getExportAbsolutePath().'/simulator/www/res/');

		// list of imported gadgets
		$importedPackages = array();
		$jsInit = $jsCore = $jsEnd = '';
		
		// load index.html (main template)
		$indexHtml = new \DOMDocument();
		@$indexHtml->loadHtmlFile($this::getExportAbsolutePath() . '/simulator/www/index.html');
		
		///////////////////////////////////////////////////
		// Generate config.xml
		//////////////////////////////////////////////////
		$configXml = new \DOMDocument('1.0','UTF-8');
		$configXml->formatOutput = true;//TODO remove after debug
		$widget = $configXml->createElement('widget');
		$widget->setAttribute('xmlns','http://www.w3.org/ns/widgets');
		$widget->setAttribute('xmlns:gap','http://phonegap.com/ns/1.0');
		$widget->setAttribute('id',$app->getPackageName());
		$widget->setAttribute('version','1.0');

		$configXml->appendChild($widget);

		$widget->appendChild($configXml->createElement('name',$app->getName()));
		$widget->appendChild($configXml->createElement('description',$app->getDescription()));
		$author = $configXml->createElement('author',$app->getAuthor());
		$author->setAttribute('href',$app->getWebsite());
		$author->setAttribute('email',$app->getEmail());
		$widget->appendChild($author);
		
		$mainPage = null;
		
		$paramGadget = array();
		$paramGadget['host'] = $this->container->getParameter('url_base').$this->container->getParameter('url_param');
	
		////////////////////////////////////////
		// Generate view for each page
		////////////////////////////////////////
		foreach ($pages as $page) {
		
			if($mainPage == null) {
				$mainPage = $page->getId();
			}
			if($page->getIsMain()) {
				$mainPage = $page->getId();
			}

			$document = new \DOMDocument();
			@$document->loadHtmlFile(TemplateUtil::getPageTemplateAbsolutePath().$page->getTemplateId());

			$finder = new \DOMXPath($document);
			$classname = "zone";//Find all the zone div in page template
			$zones = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' $classname ')]");

			foreach ($zones as $zone) {
				$zoneId = $zone->getAttribute('id');
				//Look if there is a shared gadget in this zone
				$gadget = $em->getRepository('KeosuCoreBundle:Gadget')->findSharedByZoneAndApp($zoneId,$appId);
				//If there is no share gadget we try to find the specific one
				if($gadget == null){
					//Find the gadget associated with page and zone
					$gadget = $em->getRepository('KeosuCoreBundle:Gadget')->findOneBy(array('zone' => $zoneId, 'page' => $page->getId()));
				}

				if ($gadget != null) {

					// import if it's needed
					if(array_search($gadget->getName(),$importedPackages) === false) {
						try {
							$this->importPackage($gadget->getName(),$indexHtml,$configXml,$jsInit,$jsCore,$jsEnd,$importedPackages,$app);
						} catch(\Exception $e) {
							throw new \LogicException('Unable to import '.$gadget->getName().' because '.$e->getMessage());
						}
					}

					$package = $this->packageManager->findPackage($gadget->getName());
					$packageConfig = $this->packageManager->getConfigPackage($package->getPath());
					
					// set param for gadgets
					$paramGadget['gadgetId'] = $gadget->getId();
					$paramGadget['pageId'] = $page->getId();
					$paramGadget['gadgetParam'] = array();
					$paramGadget['gadgetParam'] = $this->secureParameters($gadget->getConfig(),$packageConfig);
					
					// globalParam
					$paramGadget['appParam'] = array();
					if(isset($app->getConfigPackages()[$gadget->getName()]))
						$paramGadget['appParam'] = $this->secureParameters(
															$app->getConfigPackages()[$gadget->getName()],
															$packageConfig
													);
					
					
					
					//Copy in HTML
					$gadgetTemplateHtml = file_get_contents($package->getPath().'/templates/'.$gadget->getTemplate());
					$gadgetTemplateHtml = utf8_encode($gadgetTemplateHtml);
					$zone->nodeValue = $gadgetTemplateHtml;
					//Add the angularJS directive to zone
					// import param
					$zone->setAttribute('ng-controller', $gadget->getName().'Controller');
					$zone->setAttribute('ng-init','init('.json_encode($paramGadget).')');
					//Saving node
					$zone->ownerDocument->saveXML($zone);
				}
			}

			$bodyEl = $document->getElementsByTagName('body')->item(0);
			$html = $this->DomInnerHTML($bodyEl);
			$html = StringUtil::decodeString($html);
			$this->writeFile($html, $page->getFileName(),'/simulator/www/');
		}

		///////////////////////////////////////////////////
		// Generate main view index.html
		///////////////////////////////////////////////////

		// import theme in index.html
		$tmpthemeHeader = new \DOMDocument();
		@$tmpthemeHeader->loadHtmlFile(ThemeUtil::getAbsolutePath() .$app->getTheme().'/header/header.html');
		
		$children = $tmpthemeHeader->getElementsByTagName('head')->item(0)->childNodes;
		foreach($children as $child) {
			$indexHtml->getElementsByTagName('head')->item(0)->appendChild($indexHtml->importNode($child));
		}

		// import weinre if the app is in debug mode
		// @see https://people.apache.org/~pmuellr/weinre/docs/latest/Home.html
		if($app->getDebugMode() == true) {
			$script = $indexHtml->createElement('script');
			$script->setAttribute('src',\substr($baseurl,0,\strlen($baseurl)-10).':8080/target/target-script-min.js#anonymous');
			$indexHtml->getElementsByTagName('head')->item(0)->appendChild($script);
		}

		// this should always be at the end
		$script = $indexHtml->createElement('script');
		$script->setAttribute('src','js/app.js');
		$indexHtml->getElementsByTagName('head')->item(0)->appendChild($script);

		$this->writeFile(StringUtil::decodeString($indexHtml->saveHTML()),'index.html','/simulator/www/');

		////////////////////////////////////////////////////
		// import all gadget requiered controller
		////////////////////////////////////////////////////
		$appJs = 'var importedPackages = '.\json_encode($importedPackages).";\n";
		$appJs .= $jsInit.$jsCore.$jsEnd;
		$this->writeFile($appJs,'app.js','/simulator/www/js/');
		
		////////////////////////////////////////////////////
		// import folder if they exist
		////////////////////////////////////////////////////
		/* TODO
		foreach($importedGadget as $gadget) {
		
			$path = TemplateUtil::getAbsolutePath().DIRECTORY_SEPARATOR.'gadget'.DIRECTORY_SEPARATOR.$gadget;
			$dirs = scandir($path);
			foreach($dirs as $dir) {
				if($dir != '.' && $dir != '..' && is_dir($path. DIRECTORY_SEPARATOR .$dir)) {
					FilesUtil::copyFolder($path. DIRECTORY_SEPARATOR .$dir,
						$this::getExportAbsolutePath() . DIRECTORY_SEPARATOR .'simulator'. DIRECTORY_SEPARATOR .'www'. DIRECTORY_SEPARATOR .$dir);
				}
			}
		}*/

		//Enable individual API permissions here.
		//The "device" permission is required for the 'deviceready' event.
		$device = $configXml->createElement('feature');
		$device->setAttribute('name','http://api.phonegap.com/1.0/device');
		$widget->appendChild($device);

		// Render preferences
		$preferences = $app->getPreferences();
		foreach($preferences as $p) {
			$preference = $configXml->createElement('preference');
			$preference->setAttribute($p['key'],$p['value']);
			$widget->appendChild($preference);
		}


		// Define app icon for each platform.
		$icons = array(
			array( "src"=>"icon.png"),
			// ANDROID
			array( "src"=>"res/icons/android/iconA36.png" ,"gap:platform"=>"android","gap:density"=>"ldpi"),
			array( "src"=>"res/icons/android/iconA48.png" ,"gap:platform"=>"android","gap:density"=>"mdpi"),
			array( "src"=>"res/icons/android/iconA72.png" ,"gap:platform"=>"android","gap:density"=>"hdpi"),
			array( "src"=>"res/icons/android/iconA96.png" ,"gap:platform"=>"android","gap:density"=>"xhdpi"),
			// IOS
			array( "src"=>"res/icons/ios/iconI57.png" ,"gap:platform"=>"ios","width"=>"57","height"=>"57"),
			array( "src"=>"res/icons/ios/iconI72.png" ,"gap:platform"=>"ios","width"=>"72","height"=>"72"),
			array( "src"=>"res/icons/ios/iconI114.png" ,"gap:platform"=>"ios","width"=>"114","height"=>"114"),
			array( "src"=>"res/icons/ios/iconI120.png" ,"gap:platform"=>"ios","width"=>"120","height"=>"120"),
			array( "src"=>"res/icons/ios/iconI76.png" ,"gap:platform"=>"ios","width"=>"76","height"=>"76"),
			array( "src"=>"res/icons/ios/iconI152.png" ,"gap:platform"=>"ios","width"=>"152","height"=>"152"),
			array( "src"=>"res/icons/ios/iconI144.png" ,"gap:platform"=>"ios","width"=>"144","height"=>"144"),
		);
		
		foreach($icons as $i) {
			$icon = $configXml->createElement('icon');
			foreach($i as $k => $v) {
				$icon->setAttribute($k,$v);
			}
			$widget->appendChild($icon);
		}
		
		
		// Define app splash screen for each platform.
		$splashScreen = array(
			// ANDROID
			array( "src"=>"res/screen/android/splashscreenA320x436.png" ,"gap:platform"=>"android" ,"gap:density"=>"ldpi"),
			array( "src"=>"res/screen/android/splashscreenA320x470.png" ,"gap:platform"=>"android" ,"gap:density"=>"mdpi"),
			array( "src"=>"res/screen/android/splashscreenA640x480.png" ,"gap:platform"=>"android" ,"gap:density"=>"hdpi"),
			array( "src"=>"res/screen/android/splashscreenA960x720.png" ,"gap:platform"=>"android" ,"gap:density"=>"xhdpi"),
			// IOS
			array( "src"=>"res/screen/ios/splashscreenI320x480.png" ,"gap:platform"=>"ios" ,"width"=>"320" ,"height"=>"480"),
			array( "src"=>"res/screen/ios/splashscreenI640x960.png" ,"gap:platform"=>"ios" ,"width"=>"640" ,"height"=>"960"),
			array( "src"=>"res/screen/ios/splashscreenI640x1136.png" ,"gap:platform"=>"ios" ,"width"=>"640" ,"height"=>"1136"),
			array( "src"=>"res/screen/ios/splashscreenI1024x748.png" ,"gap:platform"=>"ios" ,"width"=>"1024" ,"height"=>"748"),
			array( "src"=>"res/screen/ios/splashscreenI768x1004.png" ,"gap:platform"=>"ios" ,"width"=>"768" ,"height"=>"1004"),
			array( "src"=>"res/screen/ios/splashscreenI2048x1496.png" ,"gap:platform"=>"ios" ,"width"=>"2048" ,"height"=>"1496"),
			array( "src"=>"res/screen/ios/splashscreenI1536x2008.png" ,"gap:platform"=>"ios" ,"width"=>"1536" ,"height"=>"2008"),
		);
		
		foreach($splashScreen as $asplash) {
			$splash = $configXml->createElement('gap:splash');
			foreach($asplash as $k=>$v)
				$splash->setAttribute($k,$v);
			$widget->appendChild($splash);
		}
		
		// Define access to external domains.
		// <access), - a blank access tag denies access to all external resources.
		// <access origin="*"), - a wildcard access tag allows access to all external resource.
		// Otherwise, you can specify specific domains:
		// <access origin="http://phonegap.com"), - allow any secure requests to http://phonegap.com/
		// <access origin="http://phonegap.com" subdomains="true"), - same as above, but including subdomains, such as http://build.phonegap.com/
		// <access origin="http://phonegap.com" browserOnly="true"), - only allows http://phonegap.com to be opened by the child browser.
		$access = array(
			array('origin' => '*')
		);
		foreach($access as $a) {
			$accesses = $configXml->createElement('access');
			foreach($a as $k => $v)
				$accesses->setAttribute($k,$v);
			$widget->appendChild($accesses);
		}

		$configXml->save($this::getExportAbsolutePath().DIRECTORY_SEPARATOR.'simulator'.DIRECTORY_SEPARATOR.'www'.DIRECTORY_SEPARATOR.'config.xml');


		/**
		 * Duplicate Export for ios, android and phonegapbuild
		 */
		//For ios
		FilesUtil::copyFolder($this::getExportAbsolutePath().'/simulator/www',
			$this::getExportAbsolutePath().'/ios/www');
		
		copy(TemplateUtil::getAbsolutePath().'/main-header/ios/cordova.js',
			$this::getExportAbsolutePath().'/ios/www/cordova.js');
		
		//For Android
		FilesUtil::copyFolder($this::getExportAbsolutePath().'/simulator/www',
			$this::getExportAbsolutePath().'/android/www');
		
		copy(TemplateUtil::getAbsolutePath().'/main-header/android/cordova.js',
			$this::getExportAbsolutePath().'/android/www/cordova.js');
		
		//For phonegapbuild
		FilesUtil::copyFolder($this::getExportAbsolutePath().'/simulator/www',
			$this::getExportAbsolutePath().'/phonegapbuild/www');
		//No cordova.js in phonegapbuild
		
		copy(TemplateUtil::getAbsolutePath().'/main-header/ios/cordova.js',
			$this::getExportAbsolutePath().'/simulator/www/cordova.js');
		
		
		//Generate ZIP files for all

		//ios
		ZipUtil::ZipFolder($this::getExportAbsolutePath().'/ios/www',
			$this::getExportAbsolutePath().'/ios/export.zip');
		//android
		ZipUtil::ZipFolder($this::getExportAbsolutePath().'/android/www',
			$this::getExportAbsolutePath().'/android/export.zip');
		//Phonegapbuild
		ZipUtil::ZipFolder($this::getExportAbsolutePath().'/phonegapbuild/www',
			$this::getExportAbsolutePath().'/phonegapbuild/export.zip');

	}

	private function cleanDir()
	{
		//Clean existing export dir
		FilesUtil::deleteDir($this::getExportAbsolutePath() .'/simulator/www');
		FilesUtil::deleteDir($this::getExportAbsolutePath() .'/ios/www');
		FilesUtil::deleteDir($this::getExportAbsolutePath() .'/android/www');
		FilesUtil::deleteDir($this::getExportAbsolutePath() .'/phonegapbuild/www');
		
		//Creating dir www and js
		mkdir($this::getExportAbsolutePath() . '/simulator/www/gadget', 0777, true);
		mkdir($this::getExportAbsolutePath() . '/simulator/www/theme', 0777, true);
		mkdir($this::getExportAbsolutePath() . '/simulator/www/js', 0777, true);
		mkdir($this::getExportAbsolutePath() . '/simulator/www/data', 0777, true);
		mkdir($this::getExportAbsolutePath() . '/simulator/www/res', 0777, true);
		
		mkdir($this::getExportAbsolutePath() . '/ios/www', 0777, true);
		mkdir($this::getExportAbsolutePath() . '/android/www', 0777, true);
		mkdir($this::getExportAbsolutePath() . '/phonegapbuild/www', 0777, true);
	}

	//write a file
	private function writeFile($html, $fileName, $path)
	{
		//Writting the html content in file
		$destiPath = $this::getExportAbsolutePath() . $path;
	
		$fileName = $destiPath . $fileName;
	
		if (file_exists($fileName)) {
			unlink($fileName);
		}
		$file = fopen($fileName, "x+");
		fwrite($file, $html);
		fclose($file);
	}
	
	/**
	 * Allow to have innerhtml of a DomNode
	 * @see https://stackoverflow.com/questions/2087103/innerhtml-in-phps-domdocument
	 */
	private function DOMinnerHTML($element) 
	{ 
		$innerHTML = ""; 
		$children = $element->childNodes;

		foreach ($children as $child) 
			$innerHTML .= $element->ownerDocument->saveHTML($child);

		return $innerHTML; 
	}

	/**
	 * @param string $packageName name of the package to import
	 * @param DOMDocument $indexDocument index.html file
	 * @param DOMDocument $configXml config.xml document
	 * @param string $jsInit js init part
	 * @param string $jsCore main part of the js
	 * @param string $jsEnd end part of the js
	 * @param array $importedPackages list of imported gadget
	 * @param App $app app to export
	 */
	private function importPackage($packageName,&$indexDocument,&$configXml,&$jsInit,&$jsCore,&$jsEnd,&$importedPackages,&$app)
	{
		$package = $this->packageManager->findPackage($packageName);
		$importedPackages[] = $package->getName();
		$config = $this->packageManager->getConfigPackage($package->getPath());
		
		// check dependency
		if(isset($config['require'])) {
			$require = $config['require'];
			if(count($require)) {
				foreach($require as $r) {
					if(array_search($r['name'],$importedPackages) === false) {
						$this->importPackage($r['name'],$indexDocument,$configXml,$jsInit,$jsCore,$jsEnd,$importedPackages,$app);
					}
				}
			}
		}
		
		// getApp config for gadget
		$appConfig = array();
		if(isset($app->getConfigPackages()[$package->getName()]))
			$appConfig = $app->getConfigPackages()[$package->getName()];

		// config Xml
		if(isset($config['configCordova'])) {
			$configCordova = $config['configCordova'];
			if(count($configCordova)) {
				$this->convertToXml($configCordova,$configXml,$configXml->getElementsByTagName('widget')->item(0),$appConfig);
			}
		}

		// javascript lib
		if(isset($config['libJs'])) {
			$libJs = $config['libJs'];
			if(count($libJs)) {
				foreach($libJs as $l) {
					$script = $indexDocument->createElement('script');
					if(substr($l,0,8) === 'https://' || substr($l,0,7) === 'http://') {
						$script->setAttribute('src',$l);
					} else {
						copy($package->getPath().DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.$l,
							$this::getExportAbsolutePath() .DIRECTORY_SEPARATOR.'simulator'.DIRECTORY_SEPARATOR.'www'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.$l);
						$script->setAttribute('src','js/'.$l);
					}
					$indexDocument->getElementsByTagName('head')->item(0)->appendChild($script);
					
				}
			}
		}
		
		// javascript script
		if(is_file($package->getPath().DIRECTORY_SEPARATOR.'init.js'))
			$jsInit .= file_get_contents($package->getPath().DIRECTORY_SEPARATOR.'init.js');
		if(is_file($package->getPath().DIRECTORY_SEPARATOR.'core.js'))
			$jsCore .= file_get_contents($package->getPath().DIRECTORY_SEPARATOR.'core.js');
		if(is_file($package->getPath().DIRECTORY_SEPARATOR.'end.js'))
			$jsEnd .= file_get_contents($package->getPath().DIRECTORY_SEPARATOR.'end.js');
			
		// event to add new feature
		$dispatcher = $this->container->get('event_dispatcher');
		$event = new ExportPackageEvent($indexDocument,$configXml);
		$dispatcher->dispatch(KeosuEvents::PACKAGE_EXPORT.$package->getName(),$event);
		
		if($event->getJsInit() !== null)
			$jsInit.= $event->getJsInit();
		
		if($event->getJsCore() !== null)
			$jsCore.= $event->getJsCore();
		
		if($event->getJsEnd() !== null)
			$jsEnd.= $event->getJsEnd();
	}

	private function convertToXml($node,\DOMDocument &$configXml,&$currentNode,$configAppForPackage)
	{
		foreach($node as $tag) {
			$tagName = array_keys($tag)[0];
			if($tagName === '@attributes') {
				foreach($tag[$tagName] as $key => $value) {
					// auto fill if the param is of type @@key@@
					$keyToFind = substr($value,2,-2);
					if(substr($value,-2) === '@@' && substr($value,0,2) == '@@' && isset($configAppForPackage[$keyToFind])) {
						$currentNode->setAttribute($key,$this->phpToXmlSpecialValue($configAppForPackage[$keyToFind]));
					} else {
						$currentNode->setAttribute($key,$value);
					}
				}
			} elseif($tagName === '@text') {
				$text = $configXml->createTextNode($tag[$tagName]);
				$currentNode->appendChild($text);
			} else {
				$element = $configXml->createElement($tagName);
				$this->convertToXml($tag[$tagName],$configXml,$element,$configAppForPackage);
				$currentNode->appendChild($element);
			}
		}
	}
	
	/**
	 * This function allow to convert php value to xml
	 * @param $value value to convert in xml
	 * @return string
	 */
	private function phpToXmlSpecialValue($value)
	{
		$ret = $value;
		if($value === false)
			$ret = 'false';
		if($value === true)
			$ret = 'true';
		return $ret;
	}
	
	/**
	 * This fonction remove the parameter that are only in the backoffice
	 * @param array $config config to secure
	 * @param array $configPackage config of the package
	 * @return array
	 */
	private function secureParameters($config,$configPackage) {
		$ret = array();
		$backOfficeOnly = array();
		if(isset($configPackage['param'])) {
			foreach($configPackage['param'] as $p) {
				if(isset($p['backOfficeOnly']))
					$backOfficeOnly[] = $p['name'];
			}
		}

		if(isset($configPackage['appParam'])) {
			foreach($configPackage['appParam'] as $p) {
				if(isset($p['backOfficeOnly']))
					$backOfficeOnly[] = $p['name'];
			}
		}

		foreach($config as $k => $c) {
			if(\array_search($k,$backOfficeOnly) === false)
				$ret[$k]=$c;
		}
		return $ret;
	}
}
?>
