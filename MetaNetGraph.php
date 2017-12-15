<?php
  /**
   * MetaNet Graph Parser Functions
   *
   *
   */

if ( !defined( 'MEDIAWIKI' ) ) {
        echo "Not a valid entry point";
        exit( 1 );
}

// Take credit for your work.
$wgExtensionCredits['parserhook'][] = array(
 
   // The full path and filename of the file. This allows MediaWiki
   // to display the Subversion revision number on Special:Version.
   'path' => __FILE__,
 
   // The name of the extension, which will appear on Special:Version.
   'name' => 'MetaNet Graphing Parser Functions',
 
   // A description of the extension, which will appear on Special:Version.
   'description' => 'Includes parser functions for processing MetaNet relations'.
		' record properties, and generating DOT format graphs',
 
   // Alternatively, you can specify a message key for the description.
   //   'descriptionmsg' => 'parsemetarecords-desc',
 
   // The version of the extension, which will appear on Special:Version.
   // This can be a number or a string.
   'version' => MetaNetGrapher::VERSION, 
 
   // Your name, which will appear on Special:Version.
   'author' => 'Jisup Hong',
 
   // The URL to a wiki page/web page with information about the extension,
   // which will appear on Special:Version.
   'url' => 'https://metanet.icsi.berkeley.edu/metaphor',
 
);

// Take credit for your work, in the "api" category.
$wgExtensionCredits['api'][] = array(

		'path' => __FILE__,

		// The name of the extension, which will appear on Special:Version.
		'name' => 'MetaNet Graphviz Service API',

		// A description of the extension, which will appear on Special:Version.
		'description' => 'API extension to run dot as a service',

		// Alternatively, you can specify a message key for the description.
		//'descriptionmsg' => 'sampleapiextension-desc',

		// The version of the extension, which will appear on Special:Version.
		// This can be a number or a string.
		'version' => 1,

		// Your name, which will appear on Special:Version.
		'author' => 'Jisup Hong',

		// The URL to a wiki page/web page with information about the extension,
		// which will appear on Special:Version.
		'url' => 'https://metanet.icsi.berkeley.edu/metaphor',

);

// Specify the function that will initialize the parser function.
$wgHooks['ParserFirstCallInit'][] = 'MetaNetGrapher::setup';

// Map class name to filename for autoloading
$wgAutoloadClasses['ApiGraphVizService'] = __DIR__ . '/ApiGraphVizService.php';

// Map module name to class name
$wgAPIModules['graphvizservice'] = 'ApiGraphVizService';

// Allow translation of the parser function name
$dir = dirname(__FILE__) . DIRECTORY_SEPARATOR;
$wgExtensionMessagesFiles['MetaNetGraph'] = $dir . 'MetaNetGraph.i18n.php';
$wgExtensionMessagesFiles['MetaNetGraphMagic'] = $dir . 'MetaNetGraph.i18n.magic.php';

$wgUseMWJquery = true;

$wgResourceModules['ext.MetaNetGraph'] = array(
        'scripts' => array('viz.js','enableviz.js'),
        'styles' => array(),
        'dependencies' => array('jquery.ui.progressbar'),
        'localBasePath' => dirname( __FILE__ ),
        'remoteExtPath' => basename( dirname( __FILE__ ) ),
        'position' => 'top'
);

$wgHooks['BeforePageDisplay'][] = 'wfMetaNetGraphAddModules';
                 
function wfMetaNetGraphAddModules( &$out, $skin = false ) {
        $out->addModules( array('ext.MetaNetGraph') );
        return true;
}

set_error_handler('exceptions_error_handler');

function exceptions_error_handler($severity, $message, $filename, $lineno) {
  if (error_reporting() == 0) {
    return;
  }
  if (error_reporting() & $severity) {
    throw new ErrorException($message, 0, $severity, $filename, $lineno);
  }
}

class MetaNetGrapher
{
    const VERSION = "1.0";
    const EDGE_FONT_SIZE = "8.0";
    const NODE_FONT_SIZE = "10.0";

    private static $parserFunctions = array('relationsgraph' => 'genRelationsGraph',
                                            'framerelclosure' => 'getClosureOfFrameRel',
                                            'searchmetaphorsbysource' => 'searchGeneralCMsBySource',
    										'frametoconcepts' => 'frameToConcepts',
    										'concepttoframes' => 'conceptToFrames',
                                            'svgtest' => 'svgtest',
    										);
    
    private static $unrankedRelations = array('?' => 1,
                                              'is related to' => 1,
                                              'is in some way related to' => 1,
                                              'is in some source relation to' => 1,
                                              'is in some target relation to' => 1,
                                              'is in a dual relationship with' => 1);

    private static $relationStyle = array('?' => 'color="dimgray"',
                                          'is subcase of' => 'color="magenta"',
                                          'is a subcase of' => 'color="magenta"',
                                          'is a target subcase of' => 'color="magenta"',
                                          'is a source subcase of' => 'color="magenta"',
                                          'is both a source and target subcase of' => 'color="magenta"',
                                          'is an entailment of' => 'color="orange"',
                                          'makes use of' => 'color="green"',
                                          'is related to' => 'color="dimgray"',
                                          'is in some way related to' => 'color="dimgray"',
                                          'is in some source relation to' => 'color="dimgray"',
                                          'is in some target relation to' => 'color="dimgray"',
                                          'is in a dual relationship with' => 'color="darkgreen"',
                                          'is a mapping within' => 'color="brown"',
                                          'has as transitive subpart 1' => 'color="purple"',
                                          'has as transitive subpart 2' => 'color="purple"');

    // Tell MediaWiki that the parser function exists.
    public static function setup( &$parser ) {
 
        foreach( self::$parserFunctions as $hook => $function )
            $parser->setFunctionHook( $hook,
                                      array( __CLASS__, $function ));

        // Return true so that MediaWiki continues to load extensions.
        return true;
    }
 
    /*
     * genRelationsGraph
     * 
     * takes "Related metaphor/frame" record property objects and a graphviz
     * graph
     * 
     * graphname: the name of the graph to generate (needed in order to have
     *      multiple graphs on the same page)
     * pivotnode: a privileged node (if given, only edges that refer to this
     *      node will be displayed)
     * incoming: edges coming into the pivot node
     * outgoing: edges going out of the pivot node
     *      edge lists are assumed to be in semantic forms results array format
     *      with <SEP> as separator, and with standard multiproperty-property
     *      delimiters
     * format: ul (unordered list) or gv (graphviz graph)
     * nodeops: options to specify for nodes (used to turn them square or round)
     * defaultNS: namespace to use for unqualified subjects/objects
     * suppressranks: used to turn off edge ranking completely
     * morerels: additional relations to include in the graph
     *   Format: <SEP>METAPHOR NAME<PROP>Source<PROP>Target<SEP>...
     *
     */
    public static function genRelationsGraph( &$parser, $graphname = 'metagraph',
                                              $pivotnode = '', $incoming = '',
                                              $outgoing = '', $format = 'ul',
                                              $nodeops = '', $defaultNS = '',
                                              $suppressranks='',
                                              $morerels='') {
        
        global $wgScriptPath;

        //return "<pre>incoming:".$incoming."<br/>outgoing:".$outgoing."</pre>";

        $uloutput = "";
        $gvoutput = "";
        if ($format=="test") {
            $gvoutput .= "<pre>";
        }
        $gvoutput .= "<div class='graphviz'>\ndigraph $graphname {\n";
        $gvoutput .= "rankdir=\"BT\"\n";
        $pre = "*";
        $graphnodes = array();
        $graphedges = array();
        $nodenum = 0;

        if ($defaultNS <> "") {
            $defaultNS .= ":";
        }

        $inandout = str_replace("[[SMW::on]]","",str_replace("[[SMW::off]]","",$incoming."<SEP>".$outgoing));

        // parse the metaphor/frame relation
        $relationsets = explode("<SEP>", $inandout);
        foreach ($relationsets as $relationset) {
            $relationset = trim($relationset);
            if ($relationset=="") {
                continue;
            }
            
            // split relationset into sub and pred
            try {
                $propparts = explode("<PROP>",$relationset);
                # this section is needed because of bizarre cases where we get
                # Frame:A<PROP>Frame:B<PROP>is subcase of<RCRD>...
                # where Frame:A is garbage
                $numparts = count($propparts);
                if ($numparts < 2 or $numparts > 3) {
                    throw new Exception("Invalid number of <PROP>s");
                }
                $subj = trim($propparts[$numparts-2]);
                $predcomplex = trim($propparts[$numparts-1]);
            } catch (Exception $e) {
                wfDebug("Error exploding relationset=$relationset:".$e->getMessage());
                continue;
            }
            # remove namespace from subject (Frame:)
            $nssubj = $subj;
            $subjparts = explode(':',$nssubj,2);            
            if ($subjparts[0] == $subj) {
                $nssubj = $defaultNS . $subj;
            } else {
                $subj = $subjparts[1];
            }

            if (empty($predcomplex)) {
                continue;
            }

            // split the predcomplex into separate preds
            $preds = explode("<MANY>",$predcomplex);
            
            foreach ($preds as $pred) {
                try {
                    // extract relation name and the object
                    $matches = explode("<RCRD>",$pred);
                    $relation = "?";
                    $ulrelation = "in is an unspecified relation to";
                    if ($matches[0] <> "") {
                        $relation = trim($matches[0]);
                        $ulrelation = trim($matches[0]);
                    }
                    $obj = trim($matches[1]);
                    if ($obj == "") {
                        continue;
                    }
                    $nsobj = $obj;

                    $objparts = explode(':',$nsobj,2);            
                    if ($objparts[0] == $obj) {
                        $nsobj = $defaultNS . $obj;
                    } else {
                        $obj = $objparts[1];
                    }
                } catch (Exception $e) {
                    wfDebug("Unable to extract relation and object from pred".
                            " ($pred) in complex($predcomplex)");
                    throw $e;
                }

                // filter out if neither end of relation involves the pivot
                if ($pivotnode <> "" and $nssubj <> $pivotnode and $nsobj <> $pivotnode) {
                    continue;
                }

                // list generation
                $uloutput .= "$pre [[$nssubj]] $relation [[$nsobj]]\n";


                // graph drawing routines
                $urlbase = $wgScriptPath . "/index.php/";
                // add nodes
                $nodes = array($nssubj, $nsobj);

                foreach ($nodes as $node) {
                    if ($node=="" || substr($node,-1)==":") {
                        continue;
                    }
                    if (array_key_exists($node,$graphnodes)==false) {
                        $nodenum++;
                        $graphnodes[$node] = "n".$nodenum;
                        $url = $urlbase . MetaNetGrapher::replaceSpaces($node);
                        $nodename = preg_replace('/^[^\s+:]+:/','',$node);
                        $attributes = "label=\"$nodename\" URL=\"$url\" fontsize=\""
                            .self::NODE_FONT_SIZE."\" ".$nodeops;
                        if ($pivotnode <> '' and $node==$pivotnode) {
                            $attributes .= "penwidth=2 style=\"filled\" fillcolor=\"lightgray\" ";
                        }
                        $gvoutput .= "$graphnodes[$node] [$attributes];\n";
                    }
                }

                // add edges only if both ends are valid nodes and if
                // the edge isn't already there
                if ($subj=="" or $obj=="" or array_key_exists($nssubj . $relation . $nsobj,$graphedges)) {
                    continue;
                }
                $edgeatts = "label = \"$relation\" fontsize=\""
                    .self::EDGE_FONT_SIZE."\" ";
                if ($suppressranks=='' and isset(self::$unrankedRelations[$relation])) {
                    $edgeatts .= " constraint=false ";
                }
                if (isset(self::$relationStyle[$relation])) {
                    $edgeatts .= self::$relationStyle[$relation] . " ";
                }
                $gvoutput .= "\n$graphnodes[$nssubj] -> $graphnodes[$nsobj] [$edgeatts];\n";
                $graphedges[$nssubj . $relation . $nsobj] = 1;
                
            }
        }
        
        // include metaphor relations (between frames if present)
        // Format: <SEP>METAPHOR NAME<PROP>Source<PROP>Target<SEP>...
        if ($morerels <> '') {
            $metaphors = explode("<SEP>", $morerels);
            foreach ($metaphors as $mset) {
                list($metaphor, $source, $target) = explode("<PROP>",$mset);
                $url = $urlbase . MetaNetGrapher::replaceSpaces($metaphor);
                $edgeatts = "label =\"$metaphor\" URL=\"$url\" fontsize=\""
                    .self::EDGE_FONT_SIZE."\" constraint=false color=red fontcolor=red";

                // if source and target are both in the graph, then add edge
                if (array_key_exists($source,$graphnodes)==true &&
                    array_key_exists($target, $graphnodes)==true) {
                    $gvoutput .= "\n$graphnodes[$source] -> $graphnodes[$target] [$edgeatts];\n";
                }
            }
        }
        
        $gvoutput .= "}\n</div>";

        if ($format == "gv") {
            return array($gvoutput,'noparse' => false);
        } else if ($format == "test") {
            $gvoutput .= "</pre>";
            return array($gvoutput, 'noparse' => true);
        } else {
            return $uloutput;
        }
    }

    /*
     * Function to retrieve the transitive closure of a frame relation or relations
     * from a starting point.
     *
     */
    public static function getClosureOfFrameRel( &$parser, $startnode = '', $relations ='' ) {
        
        global $wgRequest;

        $retstr = "";
        $oldnodes = array();
        $newnodes = array();
        $relsfilter = array();

        $relslist = explode(",",$relations);
        foreach ($relslist as $rel) {
            $retstr = "Adding $rel to the filter\n";
            $relsfilter[trim($rel)] = 1;
        }

        $newnodes[] = $startnode;
        while (count($newnodes) > 0) {

            $morenewnodes = array();
            foreach ($newnodes as $node) {

                $retstr .= "Running query on $node\n";

                if ($oldnodes[$node]==1) {
                    continue;
                }

                // make API call to retrieve related nodes
                $params = new FauxRequest(array(
                                                'action' => 'ask',
                                                'query' => '[['.$node.']]|?Related frame#type=type|+index=1|?Related frame#name=name|+index=2',
                                                )
                                          );
                $api = new ApiMain( $params, false );
                $api->execute();
                $data = & $api->getResultData();

                //$retstr .= print_r($data, true);

                $relslist   = $data["query"]["results"][$node]["printouts"]["type"];
                $retstr .= print_r($relslist, true);

                $framelist = $data["query"]["results"][$node]["printouts"]["name"];
                $retstr .= print_r($framelist, true);

                for ($i = 0; $i < count($relslist); $i++) {
                    $retstr .= "  Loop $i on results. Rel is ".$relslist[$i]."\n";
                    if ($relsfilter[trim($relslist[$i])]==1) {
                        $retstr .= "      which passes the filter\n";
                        $retstr .= "      and brings us to ".$framelist[$i]["fulltext"]."\n";
                        if (!empty($framelist[$i]["fulltext"])) {
                            $retstr .= "    Adding".$framelist[$i]["fulltext"]." to list\n";
                            $morenewnodes[] = $framelist[$i]["fulltext"];
                        }
                    }
                }

                $oldnodes[$node] = 1;
            }
            $newnodes = $morenewnodes;
        }
        unset($oldnodes[$startnode]);
        return array(implode(",", array_keys($oldnodes)),'noparse' => false);
        //return array("<pre>".$retstr."</pre>", 'noparse' => false);
    }

    /*
     * Static function to replace spaces (for URLs)
     */
    private static function replaceSpaces ($pageName) {
        return str_replace(" ","_",$pageName);
    }

    /*
     * Given a frame, the function returns
     * the metaphors that have that frame as a source frame
     * returns an array of metaphor names
     */
    private static function findMetaphors($frame) {
      //error_log("Finding metaphors for $frame");
      $qw = "[[Category:Metaphor]][[ Metaphor.Level::General]][[ Metaphor.Source frame::".
        $frame."]]";

      $params = new FauxRequest(array('action'=>'ask',
                                      'query'=>$qw));
      $api = new ApiMain( $params );
      $api->execute();
      $data = $api->getResultData();
      $metaphors = array_keys($data['query']['results']);
      //error_log("Found metaphors:".join(",",$metaphors));
      return $metaphors;
    }

    private static function findParentFrames($frame) {
      //error_log("Finding parent frames for $frame");
      $qw = "[[".$frame."]]|mainlabel=-|?Is a subcase of";
      
      $params = new FauxRequest(array('action'=>'ask',
        'query'=>$qw));
      
      $api = new ApiMain( $params );
      $api->execute();
      $data = $api->getResultData();
      $pframes = $data['query']['results'][$frame]['printouts']['Is a subcase of'];
      $parentframes = array();
      /* error_log(print_r($data,true));
       error_log("frame is ".$frame);*/
      foreach ($pframes as $frameobj) {
         array_push($parentframes, $frameobj['fulltext']);
      }
      return $parentframes;
    }

    /*
     * Search general CMs by source domain 
     */
    public static function searchGeneralCMsBySource(&$parser, $frame='') {
      
      //error_log("Frame is $frame");
      $framestack = explode(",",$frame);
      $mets = array();

      while (count($framestack) > 0) {
        $s = trim(array_shift($framestack));

        if ($s == "") {
          continue;
        }
        
        // if there is wiki link code, extract just the frame
        // (with namespace prefix)
        if (preg_match("/\[\[:([^|]+)\|{0,1}.*\]\]/",$s,$matches)) {
          $s = trim($matches[1]);
        }

        // search for metaphors using frame
        $mets = MetaNetGrapher::findMetaphors($s);
        
        // if one or more metaphors found, then discontinue loop
        if (count($mets) > 0) {
          break;
        }
        
        // if we didn't break, look for parents
        $ps = MetaNetGrapher::findParentFrames($s);
        $framestack = array_merge($framestack,$ps);
      }

      return join(",",$mets);
    }

    /*
     * Maps frame to concepts via word overlap.  Frame LUs are lemmas with pos lopped off
     * already. concept LUs come as a string IARPAConcept:CONCEPT<PROP>word,word<SEP>IARPAConcept...
     */
    public static function frameToConcepts( &$parser, $frameName='', $frameLUs='',
    		$conceptLUs='') {
    
    	global $wgScriptPath;
        if (empty($frameName)) {
            return 'Error: frame name is empty.';
        }
        if (empty($frameLUs)) {
            return 'Error: list of frame LUs is empty.';
        }
        if (empty($conceptLUs)) {
            return 'Error: list of concept LUs is empty.';
        }
    	$frameLemmas = preg_replace("/\.(a|n|v|adj|adv|p)/","",$frameLUs);
    	$frameLemmaList = explode(",",$frameLemmas);
    	$lemmaListN = count($frameLemmaList);
    	foreach ($frameLemmaList as &$lem) {
    		$lem = trim($lem);
    	}
    	$conceptChunks = explode("<SEP>",$conceptLUs);
    	$conMap = array();
    	foreach ($conceptChunks as $cchunk) {
    		try {
                list($concept,$conwords) = explode("<PROP>",$cchunk);
            } catch (Exception $e) {
                wfDebug("Error exploding cchunk=$cchunk, which came from conceptLUs=$conceptLUs:".$e->getMessage());
                continue;
            }
    		if (isset($conMap[$concept])) {
    			continue;
    		}
    		$wordList = explode(",",$conwords);
    		foreach ($wordList as &$word) {
    			$word = trim($word);
    		}
    		$conMap[$concept] = $wordList;
    	}
    	$conScoreMap = array();
    	$conComWords = array();
    	foreach ($conMap as $concept => $wordList) {
    		$commonWords = array_intersect($frameLemmaList,$wordList);
    		$mergedWords = array_unique(array_merge($frameLemmaList,$wordList), SORT_REGULAR);
    		$score = count($commonWords)/count($mergedWords);
    		$conScoreMap[$concept] = $score;
    		$conComWords[$concept] = $commonWords;
    	}
    	arsort($conScoreMap);
    	$rBuf = array();
    	$rank = 0;
    	foreach ($conScoreMap as $concept => $score) {
    		$rank += 1;
    		$commonWords = $conComWords[$concept];
    		list($pref,$cname) = explode(":",$concept);
    		if ($rank < 3) {
    			$cname = "'''".$cname."'''";
    		}
    		$lBuf = array();
    		array_push($lBuf, "[[", $concept,"|",$cname," (",
    				sprintf("#%d:%.2f",$rank,$score),";",join(",",$commonWords),")]]");
    		array_push($rBuf, join("",$lBuf));
    	}
    	return join(",<br> ",$rBuf);
    }
    public static function svgtest( &$parser ) {
        return array('<svg height="100" width="100"><circle cx="50" cy="50" r="40" stroke="black" stroke-width="3" fill="red" /> Sorry, your browser does not support inline SVG. </svg>', 'noparse'=>true, 'isHTML' =>true);
    }
    /*
     * Maps frame to concepts via word overlap.  Frame LUs come as string in the form
     * Frame:Name<PROP>lpos,lpos<SEP>Frame:Name2...
     * concept LUs come as a string IARPAConcept:CONCEPT<PROP>word,word<SEP>IARPAConcept...
     * Mappings are always from frames to concepts, so this method's computation
     * is roundabout.
     * Note that there isn't an association between frames and concepts coming in.
    */
    public static function conceptToFrames( &$parser, $conceptName='', $frameLUs,
    		$conceptLemmas='') {    
    	global $wgScriptPath;

        if (empty($conceptName)) {
            return 'Error: concept name is empty';
        }
        if (empty($frameLUs)) {
            return 'Error: list of frame LUs is empty';
        }
        if (empty($conceptLemmas)) {
            return 'Error: list of concept lemmas is empty';
        }

    	$rBuf = array();
    	$debug=False;
    	if ($debug) {
	    	array_push($rBuf, "conceptName=".$conceptName);
    		array_push($rBuf, "conceptLemmas=".$conceptLemmas);
    	}
    	#
    	# process conceptLemmas
    	# - break into concept<PROP>words chunks
    	$conChunks = explode("<SEP>",$conceptLemmas);
    	# conMap maps concepts to wordLists
    	$conMap = array();
    	$wordToCon = array();
    	foreach ($conChunks as $cchunk) {
    		# break words into array
    		list($concept,$words) = explode("<PROP>",$cchunk);
    		if (isset($conMap[$concept])) {
    			# skip if already processed the concept
    			continue;
    		}
    		$wordList = explode(",",$words);
    		foreach ($wordList as &$word) {
    			$word = trim($word);
    			if ($word) {
    				# create word to concept list mapping
    				# this is used later to find all the relevant concepts
    				# for a frame, based on its LUs
    				if (isset($wordToCon[$word])) {
    					array_push($wordToCon[$word], $concept);
    				} else {
    					$wordToCon[$word] = array($concept);
    				}
    			}
    		}
    		$conMap[$concept] = $wordList;
    		if ($debug) {
    			array_push($rBuf,"concept=".$concept." words=".join(",",$wordList));
    		}
    	}
    	# convert lpos to lemmas
    	$frameLemmas = preg_replace("/\.(a|n|v|adj|adv|p)/","",$frameLUs);
    	# break frameLemmas into frame<PROP>lemmas chunks
    	$frameChunks = explode("<SEP>",$frameLemmas);
    	$frameMap = array();
    	$frameToConcept = array();
    	foreach ($frameChunks as $schunk) {
    		# explode lemmas into a list of lemmas
    		list($frame,$lems) = explode("<PROP>",$schunk);
    		if (isset($frameMap[$frame])) {
    			continue;
    		}
    		$conList = array();
    		$lemList = explode(",",$lems);
    		foreach ($lemList as &$lem) {
    			$lem = trim($lem);
    			if ($lem) {
    				# construct list of concepts relevant for this frame
    				# by looking up it's lemmas in wordToCon
    				if (isset($wordToCon[$lem])) {
    					$conList = array_merge($conList,$wordToCon[$lem]);
    				}
    			}
    		}
    		$frameMap[$frame] = $lemList;
    		$frameToConcept[$frame] = array_unique($conList);
    		if ($debug) {
    			array_push($rBuf,"frame=".$frame." lems=".join(",",$lemList)." cons=".join(",",$conList));
    		}
    	}
    	# global here refers to rankings, scored, and words relevant to the task
    	# of mapping frames to conceptName (the concept we're finding mappings for)
    	$globalScoreMap = array();
    	$globalRankMap = array();
    	$globalCommonWords = array();
    	# figure out which concepts each frame maps to
    	foreach ($frameMap as $frame => $lemList) {
    		$conScoreMap = array();
    		$conComWords = array();
    		# iterate through all the possible concepts
    		foreach ($frameToConcept[$frame] as $concept) {
    			$wordList = $conMap[$concept];
    			$commonWords = array_intersect($lemList,$wordList);
    			$mergedWords = array_unique(array_merge($lemList,$wordList), SORT_REGULAR);
    			# score based on # words in common / total # distinct words between frame and concept
    			$score = count($commonWords)/count($mergedWords);
    			$conScoreMap[$concept] = $score;
    			$conComWords[$concept] = $commonWords;
    			if ($debug) { 
    				array_push($rBuf,"Frame ".$frame." to concept ".$concept . sprintf(" has score %f",$score));
    			}
    		}
    		# reverse sort to go from highest to lowest score
    		arsort($conScoreMap);
    		$rank = 0;
    		foreach ($conScoreMap as $concept => $score) {
    			$rank += 1;
    			$commonWords = $conComWords[$concept];
    			if ($concept==$conceptName) {
    				# this frame maps to the concept we are interested in
    				$globalRankMap[$frame] = $rank;
    				$globalScoreMap[$frame] = $score;
    				$globalCommonWords[$frame] = $commonWords;
    			}
    		}
    	}
    	# sort for display purposes: by rank, then reverse score
    	arsort($globalScoreMap);
    	asort($globalRankMap);
    	foreach ($globalRankMap as $frame => $rank) {
    		$score = $globalScoreMap[$frame];
    		$commonWords = $globalCommonWords[$frame];
    		list($pref,$frameName) = explode(":",$frame);
    		if ($rank < 3) {
    			$frameName = "'''".$frameName."'''";
    		} 
    		$lBuf = array("[[",$frame,"|",$frameName," (",sprintf("#%d:%.2f",$rank,$score),
    					  ";",join(",",$commonWords),")]]");
    		
    		array_push($rBuf,join("",$lBuf));
    	}
    	return join(",<br> ",$rBuf);
    }
    
}

