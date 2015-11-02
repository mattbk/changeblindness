<?php
/**
 * Change blindness test.
 * Visual Communication Design. Assignment 1.
 *
 * @author Raymond Jelierse <r.jelierse@student.tudelft.nl>
 *
 * This work is licensed under the Creative Commons Attribution-NonCommercial-ShareAlike 3.0 Unported License.
 * To view a copy of this license, visit http://creativecommons.org/licenses/by-nc-sa/3.0/ or send a letter to
 * Creative Commons, 444 Castro Street, Suite 900, Mountain View, California, 94041, USA.
 */

require_once 'Twig/Autoloader.php';
Twig_Autoloader::register();

session_start();

$base_dir = dirname(__FILE__);

$settings = json_decode(file_get_contents('settings.json'));

$db = new mysqli($settings->database->host, $settings->database->username, $settings->database->password, $settings->database->name);

$twig_loader = new Twig_Loader_Filesystem($base_dir.'/views');
$twig = new Twig_Environment($twig_loader, array('debug' => true));
//Need this to use dump() for debugging.
$twig->addExtension(new Twig_Extension_Debug());

$variables = array(
    'baseURL' => $settings->baseURL
);

$template = false;
$mode = !empty($_GET['mode']) ? $_GET['mode'] : 'start';
$phase = !empty($_GET['phase']) && in_array($_GET['phase'], $settings->phases) ? $_GET['phase'] : false;
$index = 0;

if (!empty($phase)) {
    $index = array_search($phase, $settings->phases, true) + 1;
}

// Store the results upon submittal.
if (!empty($phase) && isset($_POST['responsetime'])) {
    $_SESSION['results'][$phase] = array(
        'xcoordinate' => $_POST['xcoordinate'],
        'ycoordinate' => $_POST['ycoordinate'],
        'responsetime' => $_POST['responsetime']
    );

    // End of the line.
    if ($index == count($settings->phases)) {
        header('HTTP/1.0 302 Found');
        header('Location: index.php?mode=finish');
        exit;
    }
    // Next phase.
    else {
        $mode = 'phase_next';
    }
}

switch ($mode) {
    case 'start':
        $template = 'index.html.twig';
        $variables['start_url'] = 'index.php?mode=phase&phase='.$settings->phases[$index];
        break;
    case 'phase':
        $template = 'change.html.twig';
        $variables['imageWithElement'] = 'img/'.$phase.'-with.jpg';
        $variables['imageWithoutElement'] = 'img/'.$phase.'-without.jpg';
        $variables['step_count'] = $index;
		// Store the participant ID with some simple validation to prevent SQL injection
		if (isset($_POST['participantid'])) {
			$_SESSION['participantid'] = preg_replace('/[^A-Za-z0-9\. -]/', '', $_POST['participantid']);
			}          
        break;
    case 'phase_next':
        $template = 'next.html.twig';
        $variables['next_url'] = 'index.php?mode=phase&phase='.$settings->phases[$index];
        $variables['step_count'] = $index;
        break;
    case 'finish':
        $template = 'finish.html.twig';
        // Store the result.
		//Loop through $results better
		//	SELECT query should then return one row per userid per phase/scene.	
		foreach ($_SESSION['results'] as $phasename => $phasevalue) {
			//Initialize arrays
			$ready = array();
			//First piece of $ready is the phase/scene name
			$ready[] = $phasename;
			//Loop through xccordinate, ycoordinate, responsetime
			foreach ($phasevalue as $measure => $measurevalue) {
				//Put results in the right order for database
				array_push($ready, $measurevalue);
				}	

			//Calculate the score.  Compare results with targets in settings.json.
			if (($phasevalue['xcoordinate'] >= $settings->elementLocations->{$phasename }->topleft->x)
				&& ($phasevalue['xcoordinate'] < $settings->elementLocations->{$phasename}->bottomright->x)
				&& ($phasevalue['ycoordinate'] >= $settings->elementLocations->{$phasename}->topleft->y)
				&& ($phasevalue['ycoordinate'] < $settings->elementLocations->{$phasename}->bottomright->y)) {
			//Put the correct score in the ready array
					array_push($ready, '1');
					}
				else {
			//Put the incorrect score in the ready array
					array_push($ready, '0');
					}
				   			
		//Building an INSERT query:
		//Include userid (once collection form is added into the start page)				
		// DB fields are listed here:
		$columnstoimplode = array("uid", "datetime", "host", "participantid","phase", "xcoordinate", "ycoordinate","responsetime","score");
		// Note that backticks (`) go around field names...
		$columns = "`".implode("`, `", $columnstoimplode)."`";
		// Set up timestamp so you can tell participants apart.  http://alvinalexander.com/php/php-date-formatted-sql-timestamp-insert
		$timestamp = date('Y-m-d G:i:s');
		// Add the uid placeholder, timestamd, and host/IP
		$valuestoimplode = array("", $timestamp, $_SERVER['REMOTE_ADDR']);
		// Add the participant ID
		array_push($valuestoimplode,$_SESSION['participantid']);
		// Add the results
		$valuestoimplode = array_merge($valuestoimplode,$ready);
		$values  = "'".implode("', '", $valuestoimplode)."'";
		//print_r($values);
		// Build and execute query 
		//tablename
		$sql = "INSERT INTO ".$settings->database->table." (";
		$sql .= $columns;
		$sql .= ") VALUES ($values)";
		$db->query($sql) or die('Could not execute query:<br>'.mysqli_error($db));	
				
			}

        $variables['debug'] = $_SESSION['results']; 
        
        break;
    case 'results':
        $download = isset($_GET['download']);

// Check if filtering by local IP should be enabled.
        if ($settings->debug) {
            $variables['allow_filtering'] = true;
            $filtered = isset($_GET['filtered']) || $download;
        }
        else {
            $filtered = true;
        }

        if ($download) {
            header('Content-Type: text/tab-separated-values');
            header('Content-Disposition: attachment; filename="results.txt"');
            $template = 'results.txt.twig';
        }
        else {
            $template = 'results.html.twig';
        }

        if ($filtered) {
            $variables['filtered'] = true;
            //tablename
            $results = $db->query("select * from ".$settings->database->table." where host != '192.168.0.1' order by datetime asc");
        	}
        else {
        	//tablename
            $results = $db->query("select * from ".$settings->database->table." order by datetime asc");
        	}
        
        if ($results === false) {
            header('HTTP/1.0 500 Internal Server Error');
            exit;
        	}

        $variables['records'] = $results->num_rows;
        $variables['data'] = array();
        $variables['stats'] = array();

        while (($record = $results->fetch_assoc()) !== null) {
            $data = array();
            $stats = array();

			//Skip empty results (created during debugging)            
			if ($record['participantid'] != "") {
				//Put your results in one array
				$variables['data'][] = $record;  
				}
      		//here 


      		
		//Skip empty results (created during debugging)
		if ($record['responsetime'] > 0) {
            foreach ($record as $name => $value) {
    
            }    
        }


} //End while to loop through results from database.

	$results->free();
	break;    
} //End case to select page.


if (!empty ($template)) {
    echo $twig->render($template, $variables);
}
else {
    header('HTTP/1.0 404 Not Found');
}