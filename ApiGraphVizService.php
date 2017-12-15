<?php
class ApiGraphVizService extends ApiBase {

	const DOTCMD = "/usr/bin/dot -Tsvg";
	const LOGDIR = "/xa/metanet/services/mediawiki/logs";
	
	public function execute() {
		// Get specific parameters
		// Using ApiMain::getVal makes a record of the fact that we've
		// used all the allowed parameters. Not doing this would add a
		// warning ("Unrecognized parameter") to the returned data.
		// If the warning doesn't bother you, you can use 
		// $params = $this->extractRequestParams();
		// to get all parameters as an associative array (e. g. $params[ 'face' ])
		$dotcode = $this->getMain()->getVal( 'dotcode' );
		
		$descriptorspec = array(
			0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
			1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
			2 => array("file", self::LOGDIR."/gvizservice-error.log", "a") // stderr append to
		);
		
		$cwd = '/tscratch/tmp';
		$env = array();
		
		$process = proc_open(self::DOTCMD, $descriptorspec, $pipes, $cwd, $env);

		if (is_resource($process)) {
			// $pipes now looks like this:
			// 0 => writeable handle connected to child stdin
			// 1 => readable handle connected to child stdout
			// Any error output will be appended to /tmp/error-output.txt
		
			fwrite($pipes[0], $dotcode);
			fclose($pipes[0]);
		
			$svgcode = stream_get_contents($pipes[1]);
			fclose($pipes[1]);
		
			// It is important that you close any pipes before calling
			// proc_close in order to avoid a deadlock
			$return_value = proc_close($process);
		}		
		
		// Default response is a wink ;)
		$result = $this->getResult();

		// Top level
		$this->getResult()->addValue( null, $this->getModuleName(),
				array ( 'svgcode' => $svgcode ) );
		return true;
	}
 
	// Description
	public function getDescription() {
		return 'GraphViz dot to svg conversion service.';
	}
 
	// Face parameter.
	public function getAllowedParams() {
		return array_merge( array(), array(
			'dotcode' => array (
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true,
			),
		) );
	}
 
	// Describe the parameter
	public function getParamDescription() {
		return array_merge( array(), array(
			'dotcode' => 'The DOT code to convert into an SVG graph.'
		) );
	}
 
	// Get examples
	public function getExamples() {
		return array(
			'api.php?action=graphvizservice&dotcode=digraph%20simple%20%7BA%20-%3E%20B%3B%7D&format=json'
			=> 'dotcode should be passed in via POST with the DOT code'
		);
	}
}
