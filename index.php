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
        $variables['imageWithElement'] = 'img/'.$phase.'-with.png';
        $variables['imageWithoutElement'] = 'img/'.$phase.'-without.png';
        $variables['step_count'] = $index;
        break;
    case 'phase_next':
        $template = 'next.html.twig';
        $variables['next_url'] = 'index.php?mode=phase&phase='.$settings->phases[$index];
        $variables['step_count'] = $index;
        break;
    case 'finish':
        $template = 'finish.html.twig';
        // Store the result.
        $query = $db->prepare('insert into vcd_results values (null, unix_timestamp(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $query->bind_param(
            'siiiiiiiii',
            $_SERVER['REMOTE_ADDR'],
            $_SESSION['results']['scene1']['xcoordinate'],
            $_SESSION['results']['scene1']['ycoordinate'],
            $_SESSION['results']['scene1']['responsetime'],
            $_SESSION['results']['scene2']['xcoordinate'],
            $_SESSION['results']['scene2']['ycoordinate'],
            $_SESSION['results']['scene2']['responsetime'],
            $_SESSION['results']['scene3']['xcoordinate'],
            $_SESSION['results']['scene3']['ycoordinate'],
            $_SESSION['results']['scene3']['responsetime']
        ) or die('Could not prepare query');
        $query->execute() or die('Could not execute query:<br>'.mysqli_error($db));
        $query->close();
        

		//Loop through $results better
		//This is not done yet, but shows an example of a loop you could put a query statement in.
		//I think this will allow running only one query rather than several: http://stackoverflow.com/a/10054657/2152245
		//However, if you want to define the number of phases/scenes in settings.json only (and not in a db setup script),
		//	you have to use an EVA table here with fields like userid, phase, measure [xccordinate, ycoordinate, or responsetime], value [value for measure] at minimum.
		//	then need to rewrite the query to pull them out of the db correctly, since each userid will have one row per measure per phase.
		foreach ($_SESSION['results'] as $phasename => $phasevalue) {
			foreach ($phasevalue as $measure => $measurevalue) {
				//Building an INSERT query:
				//Include userid (once collection form is added into the start page)
				//Include a timestamp that actually works
				
				//Test output on the screen
				//echo ""."".$_SERVER['REMOTE_ADDR'].$phasename.$measure.$measurevalue."<br>";		
				
				// DB fields: uid, date, host, phase, measure, value
				// http://php.net/manual/en/function.implode.php
				$columnstoimplode = array("uid", "datetime", "host", "phase", "measure", "value");
				// Note that backticks (`) go around field names...
				$columns = "`".implode("`, `", $columnstoimplode)."`";
				// Set up timestamp so you can tell participants apart.  http://alvinalexander.com/php/php-date-formatted-sql-timestamp-insert
				$timestamp = date('Y-m-d G:i:s');
				$valuestoimplode = array("", $timestamp, $_SERVER['REMOTE_ADDR'], $phasename, $measure, $measurevalue);
				$values  = "'".implode("', '", $valuestoimplode)."'";
				// Build and execute query 
				$sql = "INSERT INTO results (";
				$sql .= $columns;
				$sql .= ") VALUES ($values)";
				$db->query($sql) or die('Could not execute query:<br>'.mysqli_error($db));
				}
			}
			
			
		//Debugging I think?
        $variables['debug'] = $_SESSION['results']; 

		
        
        break;
    case 'results':
        $download = isset($_GET['download']);

        if ($settings->debug) {
            $variables['allow_filtering'] = true;
            $filtered = isset($_GET['filtered']) || $download;
        }
        else {
            $filtered = true;
        }

        if ($download) {
            header('Content-Type: text/tab-separated-values');
            header('Content-Disposition: attachment; filename=vcd-results.txt');
            $template = 'results.txt.twig';
        }
        else {
            $template = 'results.html.twig';
        }

        if ($filtered) {
            $variables['filtered'] = true;
            $results = $db->query("select * from vcd_results where result_host != '192.168.0.1' order by result_date asc");
        }
        else {
            $results = $db->query("select * from vcd_results order by result_date asc");
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

            // Prepare data
            foreach ($record as $name => $value) {
                list($cat, $key) = explode('_', $name);
                $data[$cat][$key] = $value;
            }
            $variables['data'][] = $data;

            // Build statistics
            foreach ($settings->phases as $phase) {
                $stats[$phase] = array(
                    'correct' => (($data[$phase]['xcoordinate'] >= $settings->elementLocations->{$phase}->topleft->x)
                               && ($data[$phase]['xcoordinate'] <= $settings->elementLocations->{$phase}->bottomright->x)
                               && ($data[$phase]['ycoordinate'] >= $settings->elementLocations->{$phase}->topleft->y)
                               && ($data[$phase]['ycoordinate'] <= $settings->elementLocations->{$phase}->bottomright->y)),
                    'time' => $data[$phase]['responsetime']
                );
            }
            $variables['stats'][] = $stats;
        }

        $results->free();
        break;
}

if (!empty ($template)) {
    echo $twig->render($template, $variables);
}
else {
    header('HTTP/1.0 404 Not Found');
}