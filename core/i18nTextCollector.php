<?php
/**
 * @author Bernat Foj Capell <bernat@silverstripe.com>
 * @author Ingo Schommer <FIRSTNAME@silverstripe.com>
 * @package sapphire
 * @subpackage misc
 */
class i18nTextCollector extends Object {
	
	protected $defaultLocale;
	
	/**
	 * @var string $basePath The directory base on which the collector should act.
	 * Usually the webroot set through {@link Director::baseFolder()}.
	 * @todo Fully support changing of basePath through {@link SSViewer} and {@link ManifestBuilder}
	 */
	public $basePath;
	
	/**
	 * @var string $basePath The directory base on which the collector should create new lang folders and files.
	 * Usually the webroot set through {@link Director::baseFolder()}.
	 * Can be overwritten for testing or export purposes.
	 * @todo Fully support changing of basePath through {@link SSViewer} and {@link ManifestBuilder}
	 */
	public $baseSavePath;
	
	/**
	 * @param $locale
	 */
	function __construct($locale = null) {
		$this->defaultLocale = ($locale) ? $locale : i18n::default_locale();
		$this->basePath = Director::baseFolder();
		$this->baseSavePath = Director::baseFolder();
		
		parent::__construct();
	}
	
	/**
	 * This is the main method to build the master string tables with the original strings.
	 * It will search for existent modules that use the i18n feature, parse the _t() calls
	 * and write the resultant files in the lang folder of each module.
	 * 
	 * @uses DataObject->collectI18nStatics()
	 */	
	public function run($restrictToModule = null) {
		Debug::message("Collecting text...", false);
		
		// A master string tables array (one mst per module)
		$entitiesByModule = array();
		
		//Search for and process existent modules, or use the passed one instead
		$modules = (isset($restrictToModule)) ? array(basename($restrictToModule)) : scandir($this->basePath);

		foreach($modules as $module) {
			// Only search for calls in folder with a _config.php file (which means they are modules)  
			$isValidModuleFolder = (
				is_dir("$this->basePath/$module") 
				&& is_file("$this->basePath/$module/_config.php") 
				&& substr($module,0,1) != '.'
			);
			if(!$isValidModuleFolder) continue;
			
			// we store the master string tables 
			$entitiesByModule[$module] = $this->processModule($module);
		}
		
		// Write the generated master string tables
		$this->writeMasterStringFile($entitiesByModule);
		
		Debug::message("Done!", false);
	}
	
	/**
	 * Build the module's master string table
	 *
	 * @param string $module Module's name
	 */
	protected function processModule($module) {	
		$entitiesArr = array();

		Debug::message("Processing Module '{$module}'", false);

		// Search for calls in code files if these exists
		if(is_dir("$this->basePath/$module/code")) {
			$fileList = $this->getFilesRecursive("$this->basePath/$module/code");
			} else if('sapphire' == $module) {
			// sapphire doesn't have the usual module structure, so we'll scan all subfolders
			$fileList = $this->getFilesRecursive("$this->basePath/$module");
		}
		foreach($fileList as $filePath) {
			// exclude ss-templates, they're scanned separately
			if(substr($filePath,-3) == 'php') {
				$content = file_get_contents($filePath);
				$entitiesArr = array_merge($entitiesArr,(array)$this->collectFromCode($content, $module));
				//$entitiesArr = array_merge($entitiesArr, (array)$this->collectFromStatics($filePath, $module));
			}
		}
		
		// Search for calls in template files if these exists
		if(is_dir("$this->basePath/$module/templates")) {
			$fileList = $this->getFilesRecursive("$this->basePath/$module/templates");
			foreach($fileList as $index => $filePath) {
				$content = file_get_contents($filePath);
				// templates use their filename as a namespace
				$namespace = basename($filePath);
				$entitiesArr = array_merge($entitiesArr, (array)$this->collectFromTemplate($content, $module, $namespace));
			}
		}

		// sort for easier lookup and comparison with translated files
		asort($entitiesArr);

		return $entitiesArr;
	}

	/**
	 * Write the master string table of every processed module
	 */
	protected function writeMasterStringFile($entitiesByModule) {
		$php = '';
		
		// Write each module language file
		if($entitiesByModule) foreach($entitiesByModule as $module => $entities) {
			// Create folder for lang files
			$langFolder = $this->baseSavePath . '/' . $module . '/lang';
			if(!file_exists($this->baseSavePath. '/' . $module . '/lang')) {
				Filesystem::makeFolder($langFolder, Filesystem::$folder_create_mask);
				touch($this->baseSavePath. '/' . $module . '/lang/_manifest_exclude');
			}

			// Open the English file and write the Master String Table
			if($fh = fopen($langFolder . '/' . $this->defaultLocale . '.php', "w")) {
				if($entities) foreach($entities as $fullName => $spec) {
					$php .= $this->langArrayCodeForEntitySpec($fullName, $spec);
				}
				
				// test for valid PHP syntax by eval'ing it
				try{
					//eval($php);
				} catch(Exception $e) {
					user_error('i18nTextCollector->writeMasterStringFile(): Invalid PHP language file. Error: ' . $e->toString(), E_USER_ERROR);
				}
				
				fwrite($fh, "<?php\n\nglobal \$lang;\n\n" . $php . "\n?>");			
				fclose($fh);
				
				Debug::message("Created file: $langFolder/" . $this->defaultLocale . ".php", false);
			} else {
				user_error("Cannot write language file! Please check permissions of $langFolder/" . $this->defaultLocale . ".php", E_USER_ERROR);
			}
		}

	}
	
	/**
	 * Helper function that searches for potential files to be parsed
	 * 
	 * @param string $folder base directory to scan (will scan recursively)
	 * @param array $fileList Array where potential files will be added to
	 */
	protected function getFilesRecursive($folder, &$fileList = null) {
		if(!$fileList) $fileList = array();
		$items = scandir($folder);
		if($items) foreach($items as $item) {
			if(substr($item,0,1) == '.') continue;
			if(substr($item,-4) == '.php') $fileList[substr($item,0,-4)] = "$folder/$item";
			else if(substr($item,-3) == '.ss') $fileList[$item] = "$folder/$item";
			else if(is_dir("$folder/$item")) $this->getFilesRecursive("$folder/$item", $fileList);
		}
		return $fileList;
	}
	
	public function collectFromCode($content, $module) {
		$entitiesArr = array();
		
		$regexRule = '_t[[:space:]]*\(' .
			'[[:space:]]*("[^"]*"|\\\'[^\']*\\\')[[:space:]]*,' . # namespace.entity
			'[[:space:]]*("([^"]|\\\")*"|\'([^\']|\\\\\')*\')([[:space:]*,' . # value
			'[[:space:]]*[^,)]*)?([[:space:]]*,' . # priority (optional)
			'[[:space:]]*("([^"]|\\\")*"|\'([^\']|\\\\\')*\'))?[[:space:]]*' . # comment
		'\)';
		while (ereg($regexRule, $content, $regs)) {
			$entitiesArr = array_merge($entitiesArr, (array)$this->entitySpecFromRegexMatches($regs));
			
			// remove parsed content to continue while() loop
			$content = str_replace($regs[0],"",$content);
		}
		
		return $entitiesArr;
	}

	public function collectFromTemplate($content, $module, $fileName) {
		$entitiesArr = array();
		
		// Search for included templates
		preg_match_all('/<' . '% include +([A-Za-z0-9_]+) +%' . '>/', $content, $regs, PREG_SET_ORDER);
		foreach($regs as $reg) {
			$includeName = $reg[1];
			$includeFileName = "{$includeName}.ss";
			$filePath = SSViewer::getTemplateFileByType($includeName, 'Includes');
			$includeContent = file_get_contents($filePath);
			// @todo Will get massively confused if you include the includer -> infinite loop
			$entitiesArr = array_merge($entitiesArr,(array)$this->collectFromTemplate($includeContent, $module, $includeFileName));
		}

		// @todo respect template tags (<% _t() %> instead of _t())
		$regexRule = '_t[[:space:]]*\(' .
			'[[:space:]]*("[^"]*"|\\\'[^\']*\\\')[[:space:]]*,' . # namespace.entity
			'[[:space:]]*("([^"]|\\\")*"|\'([^\']|\\\\\')*\')([[:space:]]*,' . # value
			'[[:space:]]*[^,)]*)?([[:space:]]*,' . # priority (optional)
			'[[:space:]]*("([^"]|\\\")*"|\'([^\']|\\\\\')*\'))?[[:space:]]*' . # comment (optional)
		'\)';
		while(ereg($regexRule,$content,$regs)) {
			$entitiesArr = array_merge($entitiesArr,(array)$this->entitySpecFromRegexMatches($regs, $fileName));
			// remove parsed content to continue while() loop
			$content = str_replace($regs[0],"",$content);
		}
		
		return $entitiesArr;
	}
	
	/**
	 * @todo Fix regexes so the deletion of quotes, commas and newlines from wrong matches isn't necessary
	 */
	protected function entitySpecFromRegexMatches($regs, $_namespace = null) {
		// remove wrapping quotes
		$fullName = substr($regs[1],1,-1);
		
		// split fullname into entity parts
		$entityParts = explode('.', $fullName);
		if(count($entityParts) > 1) {
			// templates don't have a custom namespace
			$entity = array_pop($entityParts);
			// namespace might contain dots, so we explode
			$namespace = implode('.',$entityParts); 
		} else {
			$entity = array_pop($entityParts);
			$namespace = $_namespace;
		}
		
		// remove wrapping quotes
		$value = ($regs[2]) ? substr($regs[2],1,-1) : null;

		// only escape quotes when wrapped in double quotes, to make them safe for insertion
		// into single-quoted PHP code. If they're wrapped in single quotes, the string should
		// be properly escaped already
		if(substr($regs[2],0,1) == '"') $value = addcslashes($value,'\'');
		
		// remove starting comma and any newlines
		$prio = ($regs[5]) ? trim(preg_replace('/\n/','',substr($regs[5],1))) : null;
		
		// remove wrapping quotes
		$comment = ($regs[7]) ? substr($regs[7],1,-1) : null;

		return array(
			"{$namespace}.{$entity}" => array(
				$value,
				$prio,
				$comment
			)
		);
	}
	
	/**
	 * Input for langArrayCodeForEntitySpec() should be suitable for insertion
	 * into single-quoted strings, so needs to be escaped already.
	 * 
	 * @param string $entity The entity name, e.g. CMSMain.BUTTONSAVE
	 */
	public function langArrayCodeForEntitySpec($entityFullName, $entitySpec) {
		$php = '';
		
		$entityParts = explode('.', $entityFullName);
		if(count($entityParts) > 1) {
			// templates don't have a custom namespace
			$entity = array_pop($entityParts);
			// namespace might contain dots, so we implode back
			$namespace = implode('.',$entityParts); 
		} else {
			user_error("i18nTextCollector::langArrayCodeForEntitySpec(): Wrong entity format for $entityFullName with values" . var_export($entitySpec, true), E_USER_WARNING);
			return false;
		}
		
		$value = $entitySpec[0];
		$prio = (isset($entitySpec[1])) ? addcslashes($entitySpec[1],'\'') : null;
		$comment = (isset($entitySpec[2])) ? addcslashes($entitySpec[2],'\'') : null;
		
		$php .= '$lang[\'' . $this->defaultLocale . '\'][\'' . $namespace . '\'][\'' . $entity . '\'] = ';
		if ($prio) {
			$php .= "array(\n\t'" . $value . "',\n\t" . $prio;
			if ($comment) {
				$php .= ",\n\t'" . $comment . '\''; 
			}
			$php .= "\n);";
		} else {
			$php .= '\'' . $value . '\';';
		}
		$php .= "\n";
		
		return $php;
	}
	
	protected function collectFromStatics($filePath) {
		$entitiesArr = array();
		
		$classes = ClassInfo::classes_for_file($filePath);
		if($classes) foreach($classes as $class) {
			if(class_exists($class) && method_exists($class, 'provideI18nStatics')) {
				$obj = singleton($class);
				$entitiesArr = array_merge($entitiesArr,(array)$obj->provideI18nStatics());
			}
		}
		
		return $entitiesArr;
	}
	
	public function getDefaultLocale() {
		return $this->defaultLocale;
	}
	
	public function setDefaultLocale($locale) {
		$this->defaultLocale = $locale;
	}
}
?>