<?php

$debug             = isset($_GET['debug']) ? filter_var($_GET['debug'] ,FILTER_VALIDATE_BOOLEAN) : false;
$lookahead         = 10;   //
$pos_error         = 0.1; // absolute
$alignment_error   = 0.01; // absolute
$extrusion_error   = 0.15; // percent

$outFile = './output.gcode';

include "GCodeArcOptimiser.php";

$inputGcode = null;

$errorFiles = array();
if (!empty($_FILES) && !empty($_FILES['upload'])) {
    if($_FILES['upload']['error'] !== 0){
        die("Error with file.");
    } else {
        if (!is_uploaded_file($_FILES['upload']['tmp_name'])) {
            die("Could not read file.");
        } else {
            $inputGcode = file_get_contents($_FILES['upload']['tmp_name']);
            $gcode = explode("\n", $inputGcode);
            $gcode = SplFixedArray::fromArray($gcode);
        }
    }
} else if(isset($_POST['file']) || isset($_GET['file'])) {
    $input = isset($_POST['file']) ? $_POST['file'] : $_GET['file'];
    $gcode = $inputGcode = file_get_contents($input);
}

$optimiser = new GCodeArcOptimiser(
    $lookahead,
    $pos_error,
    $alignment_error,
    $extrusion_error,
    $debug
);

$output = $optimiser->process($gcode);
file_put_contents($outFile, $output);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo "<pre>";
    print_r([
        'time'                  => $output['time'],
        'timings_granular'      => $optimiser->getTimingsFormatted(),
        'total_replacements'    => $output['total_replacements'],
        'input_lines'           => $output['input_lines'],
        'output_lines'          => $output['output_lines'],
        // 'optimised'             => $output['gcode'],
        // 'original'              => $inputGcode,
    ]);
    echo "</pre>";
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'time'                  => $output['time'],
        'timings_granular'      => $optimiser->getTimingsFormatted(),
        'total_replacements'    => $output['total_replacements'],
        'input_lines'           => $output['input_lines'],
        'output_lines'          => $output['output_lines'],
        'optimised'             => $output['gcode'],
        'original'              => $inputGcode,
    ]);
}