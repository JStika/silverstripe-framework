<?php
/**
 * @package sapphire
 * @subpackage testing
 */

// Check that PHPUnit is installed
function hasPhpUnit() {
	$paths = explode(PATH_SEPARATOR, ini_get('include_path'));
	foreach($paths as $path) {
		if(substr($path,-1) == DIRECTORY_SEPARATOR) $path = substr($path,0,-1);
		if(@file_exists("$path/PHPUnit/Framework.php")) return true;
	}
	return false;
}

/**
 */
if(hasPhpUnit()) {
require_once 'PHPUnit/Framework.php';
require_once 'PHPUnit/Util/Report.php';
require_once 'PHPUnit/TextUI/TestRunner.php';
}

/**
 * Controller that executes PHPUnit tests.
 *
 * <h2>URL Options</h2>
 * - SkipTests: A comma-separated list of test classes to skip (useful when running dev/tests/all)
 * 
 * See {@link browse()} output for generic usage instructions.
 * 
 * @package sapphire
 * @subpackage testing
 */
class TestRunner extends Controller {
	/** @ignore */
	private static $default_reporter;
	
	static $url_handlers = array(
		'' => 'browse',
		'coverage' => 'coverage',
		'startsession' => 'startsession',
		'endsession' => 'endsession',
		'cleanupdb' => 'cleanupdb',
		'module/$ModuleName' => 'module',
		'all' => 'all',
		'build' => 'build',
		'$TestCase' => 'only',
	);
	
	/**
	 * Override the default reporter with a custom configured subclass.
	 *
	 * @param string $reporter
	 */
	static function set_reporter($reporter) {
		if (is_string($reporter)) $reporter = new $reporter;
		self::$default_reporter = $reporter;
	}
	
	function init() {
		parent::init();
		
		$canAccess = (Director::isDev() || Director::is_cli() || Permission::check("ADMIN"));
		if(!$canAccess) return Security::permissionFailure($this);
		
		ManifestBuilder::load_test_manifest();
		if (!self::$default_reporter) self::set_reporter(Director::is_cli() ? 'CliDebugView' : 'DebugView');
		
		if(!hasPhpUnit()) {
			die("Please install PHPUnit using pear");
		}
	}
	
	public function Link() {
		return Controller::join_links(Director::absoluteBaseURL(), 'dev/tests/');
	}
	
	/**
	 * Run test classes that should be run with every commit.
	 * Currently excludes PhpSyntaxTest
	 */
	function all() {
		$tests = ClassInfo::subclassesFor('SapphireTest');
		array_shift($tests);
		unset($tests['FunctionalTest']);
		
		// Remove tests that don't need to be executed every time
		unset($tests['PhpSyntaxTest']);
		
		foreach($tests as $class => $v) {
			$reflection = new ReflectionClass($class);
			if(!$reflection->isInstantiable()) unset($tests[$class]);
		}
	
		$this->runTests($tests);
	}
	
	/**
	 * Run test classes that should be run before build - i.e., everything possible.
	 */
	function build() {
		$tests = ClassInfo::subclassesFor('SapphireTest');
		array_shift($tests);
		unset($tests['FunctionalTest']);
		foreach($tests as $class => $v) {
			$reflection = new ReflectionClass($class);
			if(!$reflection->isInstantiable()) unset($tests[$class]);
		}
	
		$this->runTests($tests);
		
	}
	
	
	/**
	 * Browse all enabled test cases in the environment
	 */
	function browse() {
		self::$default_reporter->writeHeader();
		self::$default_reporter->writeInfo('Available Tests', false);
		if(Director::is_cli()) {
			$tests = ClassInfo::subclassesFor('SapphireTest');
			$relativeLink = Director::makeRelative($this->Link());
			echo "sake {$relativeLink}all: Run all " . count($tests) . " tests\n";
			echo "sake {$relativeLink}coverage: Runs all tests and make test coverage report\n";
			echo "sake {$relativeLink}module/<modulename>: Runs all tests in a module folder\n";
			foreach ($tests as $test) {
				echo "sake {$relativeLink}$test: Run $test\n";
			}
		} else {
			echo '<div class="trace">';
			$tests = ClassInfo::subclassesFor('SapphireTest');
			asort($tests);
			echo "<h3><a href=\"" . $this->Link() . "all\">Run all " . count($tests) . " tests</a></h3>";
			echo "<h3><a href=\"" . $this->Link() . "coverage\">Runs all tests and make test coverage report</a></h3>";
			echo "<hr />";
			foreach ($tests as $test) {
				echo "<h3><a href=\"" . $this->Link() . "$test\">Run $test</a></h3>";
			}
			echo '</div>';
		}
		
		self::$default_reporter->writeFooter();
	}
	
	function coverage() {
		$tests = ClassInfo::subclassesFor('SapphireTest');
		array_shift($tests);
		unset($tests['FunctionalTest']);
	
		$this->runTests($tests, true);
	}
	
	function cleanupdb() {
		SapphireTest::delete_all_temp_dbs();
	}
		
	/**
	 * Run only a single test class or a comma-separated list of tests
	 */
	function only($request) {
		if($request->param('TestCase') == 'all') {
			$this->all();
		} else {
			$classNames = explode(',', $request->param('TestCase'));
			foreach($classNames as $className) {
				if(!class_exists($className) || !is_subclass_of($className, 'SapphireTest')) {
					user_error("TestRunner::only(): Invalid TestCase '$className', cannot find matching class", E_USER_ERROR);
				}
			}
			
			$this->runTests($classNames);
		}
	}
	
	/**
	 * Run tests for one or more "modules".
	 * A module is generally a toplevel folder, e.g. "mysite" or "sapphire".
	 */
	function module($request) {
		$classNames = array();
		$moduleNames = explode(',', $request->param('ModuleName'));
		foreach($moduleNames as $moduleName) {
			$classesForModule = ClassInfo::classes_for_folder($moduleName);
			if($classesForModule) foreach($classesForModule as $class) {
				if(class_exists($class) && is_subclass_of($class, 'SapphireTest')) {
					$classNames[] = $class;
				}
			}
		}

		$this->runTests($classNames);
	}

	/**
	 * @param array $classList
	 * @param boolean $coverage
	 */
	function runTests($classList, $coverage = false) {
		$startTime = microtime(true);
		
		// XDEBUG seem to cause problems with test execution :-(
		if(function_exists('xdebug_disable')) xdebug_disable();
		
		ini_set('max_execution_time', 0);		
		
		$this->setUp();
		
		// Optionally skip certain tests
		$skipTests = array();
		if($this->request->getVar('SkipTests')) {
			$skipTests = explode(',', $this->request->getVar('SkipTests'));
		}
		$classList = array_diff($classList, $skipTests);
		
		// run tests before outputting anything to the client
		$suite = new PHPUnit_Framework_TestSuite();
		natcasesort($classList);
		foreach($classList as $className) {
			// Ensure that the autoloader pulls in the test class, as PHPUnit won't know how to do this.
			class_exists($className);
			$suite->addTest(new SapphireTestSuite($className));
		}

		// Remove the error handler so that PHPUnit can add its own
		restore_error_handler();

		/*, array("reportDirectory" => "/Users/sminnee/phpunit-report")*/
		if(Director::is_cli()) $reporter = new CliTestReporter();
		else $reporter = new SapphireTestReporter();

		self::$default_reporter->writeHeader("Sapphire Test Runner");
		if (count($classList) > 1) { 
			self::$default_reporter->writeInfo("All Tests", "Running test cases: " . implode(",", $classList));
		} else {
			self::$default_reporter->writeInfo($classList[0], "");
		}
		
		$results = new PHPUnit_Framework_TestResult();		
		$results->addListener($reporter);

		if($coverage) {
			$results->collectCodeCoverageInformation(true);
			$suite->run($results);

			if(!file_exists(ASSETS_PATH . '/coverage-report')) mkdir(ASSETS_PATH . '/coverage-report');
			PHPUnit_Util_Report::render($results, ASSETS_PATH . '/coverage-report/');
			$coverageApp = ASSETS_PATH . '/coverage-report/' . preg_replace('/[^A-Za-z0-9]/','_',preg_replace('/(\/$)|(^\/)/','',Director::baseFolder())) . '.html';
			$coverageTemplates = ASSETS_PATH . '/coverage-report/' . preg_replace('/[^A-Za-z0-9]/','_',preg_replace('/(\/$)|(^\/)/','',realpath(TEMP_FOLDER))) . '.html';
			echo "<p>Coverage reports available here:<ul>
				<li><a href=\"$coverageApp\">Coverage report of the application</a></li>
				<li><a href=\"$coverageTemplates\">Coverage report of the templates</a></li>
			</ul>";
		} else {
			$suite->run($results);
		}
		
		if(!Director::is_cli()) echo '<div class="trace">';
		$reporter->writeResults();

		$endTime = microtime(true);
		if(Director::is_cli()) echo "\n\nTotal time: " . round($endTime-$startTime,3) . " seconds\n";
		else echo "<p>Total time: " . round($endTime-$startTime,3) . " seconds</p>\n";
		
		if(!Director::is_cli()) echo '</div>';
		
		// Put the error handlers back
		Debug::loadErrorHandlers();
		
		if(!Director::is_cli()) self::$default_reporter->writeFooter();
		
		$this->tearDown();
		
		// Todo: we should figure out how to pass this data back through Director more cleanly
		if(Director::is_cli() && ($results->failureCount() + $results->errorCount()) > 0) exit(2);
	}
	
	/**
	 * Start a test session.
	 * Usage: visit dev/tests/startsession?fixture=(fixturefile).  A test database will be constructed, and your browser session will be amended
	 * to use this database.  This can only be run on dev and test sites.
	 */
	function startsession() {
		if(!Director::isLive()) {
			if(SapphireTest::using_temp_db()) {
				$endLink = Director::baseURL() . "/dev/tests/endsession";
				return "<p><a id=\"end-session\" href=\"$endLink\">You're in the middle of a test session; click here to end it.</a></p>";
			
			} else if(!isset($_GET['fixture'])) {
				$me = Director::baseURL() . "/dev/tests/startsession";
				return <<<HTML
<form action="$me">				
	<p>Enter a fixture file name to start a new test session.  Don't forget to visit dev/tests/endsession when you're done!</p>
	<p>Fixture file: <input id="fixture-file" name="fixture" /></p>
	<input type="hidden" name="flush" value="1">
	<p><input id="start-session" value="Start test session" type="submit" /></p>
</form>
HTML;
			} else {
				$fixtureFile = $_GET['fixture'];
			
				// Validate fixture file
				$realFile = realpath('../' . $fixtureFile);
				$baseDir = realpath(Director::baseFolder());
				if(!$realFile || !file_exists($realFile)) {
					return "<p>Fixture file doesn't exist</p>";
				} else if(substr($realFile,0,strlen($baseDir)) != $baseDir) {
					return "<p>Fixture file must be inside $baseDir</p>";
				} else if(substr($realFile,-4) != '.yml') {
					return "<p>Fixture file must be a .yml file</p>";
				} else if(!preg_match('/^([^\/.][^\/]+)\/tests\//', $fixtureFile)) {
					return "<p>Fixture file must be inside the tests subfolder of one of your modules.</p>";
				}

				$dbname = SapphireTest::create_temp_db();

				DB::set_alternative_database_name($dbname);
			
				$fixture = new YamlFixture($_GET['fixture']);
				$fixture->saveIntoDatabase();
				
				return "<p>Started testing session with fixture '$fixtureFile'.  Time to start testing; where would you like to start?</p>
					<ul>
						<li><a id=\"home-link\" href=\"" .Director::baseURL() . "\">Homepage - published site</a></li>
						<li><a id=\"draft-link\" href=\"" .Director::baseURL() . "?stage=Stage\">Homepage - draft site</a></li>
						<li><a id=\"admin-link\" href=\"" .Director::baseURL() . "admin/\">CMS Admin</a></li>
					</ul>";
			}
						
		} else {
			return "<p>startession can only be used on dev and test sites</p>";
		}
	}
	
	function endsession() {
		SapphireTest::kill_temp_db();
		DB::set_alternative_database_name(null);

		return "<p>Test session ended.</p>";
	}
	
	function setUp() {
		// The first DB test will sort out the DB, we don't have to
		SSViewer::flush_template_cache();
	}
	
	function tearDown() {
		SapphireTest::kill_temp_db();
		DB::set_alternative_database_name(null);
	}
}

// This class is here to help with documentation.
if(!hasPhpUnit()) {
/**
 * PHPUnit is a testing framework that can be installed using PEAR.
 * It's not bundled with Sapphire, you will need to install it yourself.
 * 
 * @package sapphire
 * @subpackage testing
 */
class PHPUnit_Framework_TestCase {
	
}
}