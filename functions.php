<?php

/**
 * Loops through the gcode file, continuously analysing a buffer to see
 * if it is a valid circle within a tolerance.
 * @param  SPLFixedArray $gcode The gcode as an SPLFixedArray of lines
 * @return string               The resulting GCode string
 */
function processGcode($gcode)
{
    global $debug;
    global $lookahead;
    global $start;

    // Keeps track of the amount of valid buffers (valid circles)
    $totalValidBuffers = 0;
    // The output gcode
    $output = "";
    // The looping buffer
    $buffer = new SplQueue();

    // go back to the start of our gcode
    $gcode->rewind();

    // prefill the buffer
    do {
        $buffer->enqueue($gcode->current());
        $gcode->next();
    } while($buffer->count() < $lookahead);

    // keeps track of the last valid buffer.
    $lastValid = false;
    // Loop through our gcode
    for(;$gcode->valid();$gcode->next()){
        // Don't process for too long, and during debugging, only do a couple of buffers (finding circles
        // takes a long time)
        if((microtime(true) - $start) > 120  || ($debug === true && $totalValidBuffers > 100)){
            $output .= $gcode->current()."\n"; continue;
        }
        // check to see if we have a 'valid' buffer
        $valid = bufferValid($buffer);
        // if the buffer is no longer valid and we have more items in our buffer than
        // the lookahead value, then we must have had a valid buffer at the last step
        if($valid === false){
            if($buffer->count() > $lookahead) {

                // The last element made the buffer invalid, so we pop it so that we
                // can stick it back on the end later
                $temp = $buffer->pop();
                // Our last buffer was a valid circle, so we take that
                $processed = $lastValid;
                // Double check that we had a valid buffer...
                if($processed !== false){
                    // creates a line array
                    $lines = getLines($buffer);
                    // If we're debugging, we draw the 'result' on a canvas.
                    if($debug) drawResult($processed, $lines, 'gcodeview'.$gcode->key());
                    // Generate a gcode arc from the lines
                    $output .= generateGcodeArc($processed, $lines)."\n";
                } else {
                    // otherwise we just stick the buffer on the output.
                    foreach($buffer as $num=>$buffer){
                        $output .= $buffer."\n";
                    }
                }

                // Now, we re-initialise the buffer and fill it up
                $buffer = new SplQueue();
                do {
                    $buffer->enqueue($gcode->current());
                    $gcode->next();
                } while($buffer->count() < $lookahead && $gcode->valid());

                // Increase the amount of valid buffers
                $totalValidBuffers++;

                // Stick our previously popped temp value on the end
                $output .= $temp."\n";

            } else {
                // otherwise, we dequeue a value off the buffer
                $output .= $buffer->dequeue()."\n";
            }
        }
        // record the last valid buffer
        $lastValid = $valid;
        // If we still have code to process, stick it on the buffer!
        if($gcode->valid())
            $buffer->enqueue($gcode->current());
    }

    // We're done!
    printf("Total Valid Buffers: %d | ", $totalValidBuffers);

    return $output;
}

/**
 * Generates a G2/G3 gcode arc given the describing circle and the lines used to obtain it.
 * @param  array  $circle The circle description
 * @param  array  $lines  The lines used to describe it
 * @return string         The resulting GCode
 */
function generateGcodeArc($circle, $lines)
{
    /**
     * Usage
     *   G2 Xnnn Ynnn Innn Jnnn Ennn Fnnn (Clockwise Arc)
     *   G3 Xnnn Ynnn Innn Jnnn Ennn Fnnn (Counter-Clockwise Arc)
     * Parameters
     *   Xnnn The position to move to on the X axis
     *   Ynnn The position to move to on the Y axis
     *   Innn The point in X space from the current X position to maintain a constant distance from
     *   Jnnn The point in Y space from the current Y position to maintain a constant distance from
     *   Ennn The amount to extrude between the starting point and ending point
     *   Fnnn The feedrate per minute of the move between the starting point and ending point (if supplied)
     * Examples
     *   G2 X90.6 Y13.8 I5 J10 E22.4 (Move in a Clockwise arc from the current point to point (X=90.6,Y=13.8), with a center point at (X=current_X+5, Y=current_Y+10), extruding 22.4mm of material between starting and stopping)
     *   G3 X90.6 Y13.8 I5 J10 E22.4 (Move in a Counter-Clockwise arc from the current point to point (X=90.6,Y=13.8), with a center point at (X=current_X+5, Y=current_Y+10), extruding 22.4mm of material between starting and stopping)
     */
    $startPoint  = $lines[0];
    $endPoint    = $lines[$lines->count()-1];
    $centerPoint = $circle['center'];
    $extrusion   = $endPoint['E'];
    $return      = '';

    $return .= sprintf("; ARC\nG1 X%.3f Y%.3f E%.3f\n", $startPoint['X'], $startPoint['Y'], $startPoint['E']);

    if($circle['direction'] === "clockwise")
        $return .= "G2 ";
    else
        $return .= "G3 ";

    $return .= sprintf("X%.3f Y%.3f I%.3f J%.3f E%.3f",
        $endPoint['X'],
        $endPoint['Y'],
        $centerPoint['X']-$startPoint['X'],
        $centerPoint['Y']-$startPoint['Y'],
        $extrusion
    );

    return $return;

    return "OMG WE HAVE AN ARC";
}

/**
 * Determines if we have a valid buffer for optimising.
 *
 * Has to be G1s all round with no Z movements, and either all extrusions or all movements.
 *
 * The extrusions also have to be the same mm^3/mm along the path.
 *
 * They also have to describe a circle
 * @param  SplQueue $buffer The buffer
 * @return Boolean          Whether the buffer is valid
 */
function bufferValid($buffer)
{
    global $debug;
    global $pos_error;

    for($buffer->rewind();$buffer->valid();$buffer->next()){
        if(substr($buffer->current(), 0, 2) !== "G1"){
            $buffer->rewind();
            return false;
        } else if(strpos($buffer->current(), "Z") !== FALSE) {
            $buffer->rewind();
            return false;
        }
    }
    $lines = getLines($buffer);
    $allE  = false;
    $allF  = false;
    if(!is_null($lines[0]['E'])) {
        $allE = true;
    }
    if(!is_null($lines[0]['F'])) {
        $allF = true;
    }
    foreach($lines as $num => $line){
        $allE = $allE && is_null($line['F']) && !is_null($line['E']);
        $allF = $allF && is_null($line['E']) && !is_null($line['F']);
    }
    if(!($allE || $allF)){
        $buffer->rewind();
        return false;
    }
    if($allE){
        $extrusions = getExtrusionLengths($lines);
        $eerror = calculateExtrusionError($extrusions);
        if(calculateExtrusionError($extrusions) === false){
            $buffer->rewind();
            return false;
        }
    }
    $lines->rewind();
    $circle = getCircle($lines);
    if($circle === false) {
        $buffer->rewind();
        return false;
    }
    if(max($circle['errors']) > $pos_error){
        return false;
    }
    $buffer->rewind();
    return $circle;
}

/**
 * Calculates whether the average change of extrusions along the path
 * is greater than our allowed extrusion error, $extrusion_error
 * @param  array   $extrusions The extrusions
 * @return boolean             Whether it's valid
 */
function calculateExtrusionError($extrusions)
{
    global $extrusion_error;
    foreach($extrusions['mm/mm'] as $num => $mm){
        if(abs($mm-$extrusions['avg']['mm/mm'])/$extrusions['avg']['mm/mm'] > $extrusion_error){
            return false;
        }
    }
    return true;
}

/**
 * [getExtrusionLengths description]
 * @param  [type] $lines [description]
 * @return [type]        [description]
 */
function getExtrusionLengths($lines)
{
    $extrusions = array(
        'total' => array(
            'pathlength' => 0,
            'filament'   => 0,
        ),
        'avg'        => array(
            'pathlength' => 0,
            'filament'   => 0,
            'mm/mm'      => 0,
        ),
        'filament'   => array(
        ),
        'pathlength' => array(
        ),
        'mm/mm'      => array(
        ),
    );
    $lines->rewind();
    $prev = null;
    foreach($lines as $num => $line){
        if(!is_null($prev)){
            $ls = vector_subtract($line, $prev);
            $lsLength = max(vector_magnitude($ls),0.0000001);
            $extrusions['total']['filament']   += ($line['E']-$prev['E']);
            $extrusions['total']['pathlength'] += $lsLength;
            $extrusions['filament'][$num]       = ($line['E']-$prev['E']);
            $extrusions['pathlength'][$num]     = $lsLength;
            $extrusions['mm/mm'][$num]          = ($line['E']-$prev['E'])/$lsLength;
        }
        $prev = $line;
    }
    $extrusions['avg']['filament']   = $extrusions['total']['filament']/$lines->count();
    $extrusions['avg']['pathlength'] = $extrusions['total']['pathlength']/$lines->count();
    $extrusions['avg']['mm/mm']      = $extrusions['total']['filament']/$extrusions['total']['pathlength'];
    return $extrusions;
}

/**
 * [getLines description]
 * @param  [type] $buffer [description]
 * @return [type]         [description]
 */
function getLines($buffer)
{
    $lines = new SplFixedArray($buffer->count());
    for($buffer->rewind();$buffer->valid();$buffer->next()){
        $lines[$buffer->key()] = getCoords($buffer->current());
    }
    return $lines;
}

/**
 * Finds the circle describing the given lines
 * @param  array $lines The gcode lines
 * @return array        The resulting descriptive circle
 */
function getCircle($lines)
{
    $center = getCircleCenterLeastSquares($lines);

    // $center = getCircleCenter($lines);
    // if($center === false) return false;
    // $radius = getCircleRadius($center, $lines);
    $errors = getCircleErrors($center, $center['R'], $lines);
    $direction = getCircleDirection($lines);

    return array('errors' => $errors, 'radius' => $center['R'], 'center' => $center, 'direction' => $direction);
}

/**
 * Uses the least squares method for finding the circle center from $lines
 *
 * This method is a **lot** faster than getCircleCenter, but less accurate in the
 * case of non-circular lines
 * @param  array $lines The gcode lines
 * @return array        A circle description
 */
function getCircleCenterLeastSquares($lines)
{
    $xbar = 0;
    $ybar = 0;
    $lines = $lines->toArray();
    $N    = count($lines);
    for($i = 0; $i < $N; $i++){
        $xbar += $lines[$i]['X'];
        $ybar += $lines[$i]['Y'];
    }
    $xbar /= $N;
    $ybar /= $N;
    $Suu = 0;
    $Suuu = 0;
    $Suvv = 0;
    $Suv = 0;
    $Svv = 0;
    $Svvv = 0;
    $Svuu = 0;
    for($i = 0; $i < $N; $i++){
        $lines[$i]['U'] = $lines[$i]['X'] - $xbar;
        $lines[$i]['V'] = $lines[$i]['Y'] - $ybar;
        $Suu  += $lines[$i]['U']*$lines[$i]['U'];
        $Suuu += $lines[$i]['U']*$lines[$i]['U']*$lines[$i]['U'];
        $Suvv += $lines[$i]['U']*$lines[$i]['V']*$lines[$i]['V'];
        $Suv  += $lines[$i]['U']*$lines[$i]['V'];
        $Svv  += $lines[$i]['V']*$lines[$i]['V'];
        $Svvv += $lines[$i]['V']*$lines[$i]['V']*$lines[$i]['V'];
        $Svuu += $lines[$i]['V']*$lines[$i]['U']*$lines[$i]['U'];
    }

    $v = ((($Svvv+$Svuu)/2) - (($Suv/2)*(($Suuu+$Suvv)/$Suu)))/(((-($Suv*$Suv))/$Suu)+$Svv);
    $u = ( (($Suuu+$Suvv)/2) - ($v*$Suv) )/($Suu);

    return array(
        'X'=> $u+$xbar,
        'Y'=> $v+$ybar,
        'R'=> sqrt(($u*$u)+($v*$v)+(($Suu+$Svv)/$N))
    );
}

/**
 * Gets the distances of each point from the circle
 * @param  array $center The center of the circle
 * @param  float $radius Radius of the circle
 * @param  array  $lines The gcode lines
 * @return array         An array of the errors
 */
function getCircleErrors($center, $radius, $lines)
{
    $errors = array();
    foreach($lines as $line) {
        $vec = array($center['X'] - $line['X'], $center['Y']-$line['Y']);
        $length = vector_magnitude($vec);
        $errors[] = abs($length-$radius);
    }
    return $errors;
}

/**
 * Calculates whether the vectors are clockwise or anticlockwise
 * @param  array  $lines The gcode lines
 * @return string        The chirality
 */
function getCircleDirection($lines)
{
    $edge1 = vector_subtract($lines[1], $lines[0]);
    $edge2 = vector_subtract($lines[2], $lines[1]);
    $mag = vector_magnitude_cross_product($edge1, $edge2);
    if($mag > 0){
        return 'anticlockwise';
    }
    return 'clockwise';
}

/**
 * Gets the radius the circle
 * @param  array $center The center of the circle
 * @param  array  $lines The gcode lines
 * @return array         Gets the radius of the circle based at $center that describes $lines
 */
function getCircleRadius($center, $lines)
{
    $count = $lines->count();
    $sum   = 0;
    foreach($lines as $line) {
        $vec = array($center['X'] - $line['X'], $center['Y']-$line['Y']);
        $length = sqrt(pow($vec[0], 2) + pow($vec[1], 2));
        $sum += $length;
    }
    return $sum/$count;
}

/**
 * Uses a more accurate method for finding the center of a circle
 *
 * This method is a **lot** slower than getCircleCenterLeastSquares, but more accurate in the
 * case of non-circular lines
 * @param  array $lines The gcode lines
 * @return array        A circle center (vector array('X'=>0,'Y'=>0))
 */
function getCircleCenter($lines)
{
    global $alignment_error;
    $lines->rewind();
    $dx = 0;
    $dy = 0;
    $q = 0;
    $pows = array();
    $count = $lines->count();
    for($i = 0; $i < $count; $i++) {
        $pows[$i]['X'] = $lines[$i]['X']*$lines[$i]['X'];
        $pows[$i]['Y'] = $lines[$i]['Y']*$lines[$i]['Y'];
    }
    for($i = 0; $i <= $count - 3; $i++) {
        for($j = $i+1; $j <= $count-2; $j++) {
            for($k = $j + 1; $k <= $count-1; $k++) {
                $delta = (($lines[$k]['X'] - $lines[$j]['X'])*($lines[$j]['Y'] - $lines[$i]['Y']))-(($lines[$j]['X'] - $lines[$i]['X'])*($lines[$k]['Y'] - $lines[$j]['Y']));
                if($delta === null||$delta === 0) return false;
                if(abs($delta) > $alignment_error) {
                    // we know the points are not aligned
                    $x =
                    (
                        (
                            ( $lines[$k]['Y'] - $lines[$j]['Y'] ) *
                            ( $pows[$i]['X'] + $pows[$i]['Y'] )
                        ) +
                        (
                            ( $lines[$i]['Y'] - $lines[$k]['Y'] ) *
                            ( $pows[$j]['X'] + $pows[$j]['Y'] )
                        ) +
                        (
                            ( $lines[$j]['Y'] - $lines[$i]['Y'] ) *
                            ( $pows[$k]['X'] + $pows[$k]['Y'] )
                        )
                    );
                    $x /= (2*$delta);
                    $y =
                    -(
                        (
                            ( $lines[$k]['X'] - $lines[$j]['X'] ) *
                            ( $pows[$i]['X'] + $pows[$i]['Y'] )
                        ) +
                        (
                            ( $lines[$i]['X'] - $lines[$k]['X'] ) *
                            ( $pows[$j]['X'] + $pows[$j]['Y'] )
                        ) +
                        (
                            ( $lines[$j]['X'] - $lines[$i]['X'] ) *
                            ( $pows[$k]['X'] + $pows[$k]['Y'] )
                        )
                    );
                    $y /= (2*$delta);
                    $dx += $x;
                    $dy += $y;
                    $q++;
                }
            }
        }
    }
    if($q === 0) {
        return false;
    }
    return array("X" => $dx/$q, "Y" => $dy/$q);
}

/**
 * Gets any coordinates from the gcode line
 * @param  string $line A gcode line
 * @return array        An array of any coordinates/arguments
 */
function getCoords($line)
{
    $output = array(
        "X" => null,
        "Y" => null,
        "I" => null,
        "J" => null,
        "Z" => null,
        "E" => null,
        "F" => null,
        "S" => null,
    );
    $found = preg_match_all("/ ([XYZEFSIJ])([0-9.]+)/", $line, $result);
    if($found > 0 && $found !== false){
        foreach($result[1] as $num => $coord){
            $output[$coord] = $result[2][$num];
        }
    } else if($found === false) {
        exit("AN ERROR OCCURRED WITH THE REGEX IN getCoords");
    }
    return $output;
}

/**
 * Calculates the angle between two vectors
 *
 * e.g. angleBetween(['X'=> 0, 'Y' => 0] & ['X' => 1, 'Y' => 1]);
 * @param  array  $v1 Vector 1
 * @param  array  $v2 Vector 2
 * @return float      The angle in radians
 */
function angleBetween($v1, $v2) {
    $angle = acos( vector_dot_product($v1, $v2) / (vector_magnitude($v1) * vector_magnitude($v2)))  + (3*(M_PI/4));
    return ($angle > M_PI) ? $angle - M_PI : $angle;
}

function vector_dot_product($vector1, $vector2)
{
    return (($vector1['X'] * $vector2['X']) + ($vector1['Y'] * $vector2['Y']));
}

/**
 * Calculates the magnitude of a vector.
 * @param  array $vector The vector array
 * @return float         The magnitude of the vector
 */
function vector_magnitude($vector)
{
    return sqrt(array_reduce($vector, function($carry, $item){ return $carry + pow($item,2);}, 0));
}

/**
 * Adds two vectors.
 * @param  array $vector1 The first  vector array
 * @param  array $vector2 The second vector array
 * @return array          The resultant vector
 */
function vector_add($vector1, $vector2)
{
    return array('X' => $vector1['X']+$vector2['X'], 'Y' => $vector1['Y']+$vector2['Y']);
}

/**
 * Subtracts two vectors.
 * @param  array $vector1 The first  vector array
 * @param  array $vector2 The second vector array
 * @return array          The resultant vector
 */
function vector_subtract($vector1, $vector2)
{
    return array('X' => $vector1['X']-$vector2['X'], 'Y' => $vector1['Y']-$vector2['Y']);
}

/**
 * Subtracts two vectors absolutely (the resultant vector will always be positive X and Y).
 * @param  array $vector1 The first  vector array
 * @param  array $vector2 The second vector array
 * @return array          The resultant vector
 */
function vector_abs_subtract($vector1, $vector2)
{
    return array('X' => abs($vector1['X']-$vector2['X']), 'Y' => abs($vector1['Y']-$vector2['Y']));
}

function vector_magnitude_cross_product($vector1, $vector2)
{
    return ($vector1['X']*$vector2['Y']) - ($vector1['Y']*$vector2['X']);
}

/**
 * Converts bytes to human readable code
 * @param int $size
 * @return string The human readable number
 */
function human_byte_convert($size)
{
    $unit=array('b','kb','mb','gb','tb','pb');
    return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
}

/**
 * Parses gcode string
 * @param  SPLFixedArray $gcode The gcode as an SPLFixedArray of lines
 * @return array                An array of lines where each entry is split into command and parameters
 */
function parseGcode($gcode)
{
    global $debug;
    global $lookahead;
    global $start;

    $output = array();
    $gcode->rewind();

    $lastValid = false;
    for(;$gcode->valid();$gcode->next()){
        $output[] = parseLine($gcode->current());
    }

    return array_filter($output);
}

/**
 * Parses a gcode line
 *
 * returns something like:
 *
 * array(
 *     'command' => 'G2',
 *     'arguments'  => array(
 *         'X' => 22.5,
 *         'Y' => 55.5
 *     )
 * )
 * @param  string $line         A line from the gcode
 * @param  bool   $skipNonMoves Whether to skip non move codes
 * @return array                Resultant gcode line description
 */
function parseLine($line, $skipNonMoves = true)
{
    $out = array(
        "command" => null,
        "arguments" => array(),
        "comment" => null,
    );
    $line = explode(";",$line);
    if(count($line) > 1){
        $instruction = array_shift($line);
        $out["comment"] = implode(";",$line);
    } else {
        $instruction = $line[0];
    }
    $line = strtoupper(trim($instruction));

    // Skips non moving
    if($skipNonMoves){
        $match = '/^(G0|G1|G2|G3|G90|G91|G92|M82|M83|G28)/';
        if(preg_match($match, $line) === 0){
            return false;
        }
    }
    $parts = array_map('trim',array_filter(explode(" ", $line)));
    $out["command"] = trim(array_shift($parts));
    foreach($parts as $part){
        $out['arguments'][$part[0]] = floatval(substr($part,1));
    }
    return $out;
}

/**
 * Draws a canvas of the result if we're debugging
 * @param  array  $circle The circle description
 * @param  array  $lines  The lines used to describe it
 * @param  string $id     The ID to use for the canvas
 * @return null
 */
function drawResult($circle, $lines, $id){
    global $debug;
    if($debug){
        $arc = implode("\n", array_map(function($a){ return "    ".$a;}, explode("\n",generateGcodeArc($circle, $lines))));
        $height = 500; $width = 500; $scale = 2;
        echo createCanvas($id, $height, $width);
        $canvasPoints = array();
        foreach($lines as $line){
            $canvasPoints[] = array('X' => $line['X'] * $scale, 'Y' => $line['Y'] * $scale);
        }
        // echo "<pre>";var_dump($canvasPoints);echo "</pre>";
        echo drawCanvasPoints($id, $canvasPoints);
        $line = 0;
        echo writeOnCanvas($id, "Number of nodes: " . $lines->count(), 5, $height-($line++*15));
        $ccenter = $circle['center'];
        echo pointOnCanvas($id, $ccenter['X']*$scale, $ccenter['Y']*$scale);
        echo writeOnCanvas($id, sprintf("%.1f, %.1f", $ccenter['X'], $ccenter['Y']), $ccenter['X']*$scale, $ccenter['Y']*$scale, 10, '#FF0000', 'true');
        // echo circleOnCanvas($id, $ccenter['X']*$scale, $ccenter['Y']*$scale, $circle['radius']*$scale, '#00FF00');
        // echo circleOnCanvas($id, $ccenter['X']*$scale, $ccenter['Y']*$scale, ($circle['radius']+max($circle['errors']))*2, '#FF0000');
        // echo circleOnCanvas($id, $ccenter['X']*$scale, $ccenter['Y']*$scale, ($circle['radius']-max($circle['errors']))*2, '#0000FF');

        $arcCoords  = getCoords($arc);
        $endAngle   = angleBetween(array('X' => $ccenter['X']*$scale, 'Y' => $ccenter['Y']*$scale), array('X'=>$canvasPoints[0]['X'], 'Y'=>$canvasPoints[0]['Y']));
        $startAngle = angleBetween(array('X' => $ccenter['X']*$scale, 'Y' => $ccenter['Y']*$scale), array('X'=>$canvasPoints[count($canvasPoints)-1]['X'], 'Y'=>$canvasPoints[count($canvasPoints)-1]['Y']));

        echo arcOnCanvas($id, $ccenter['X']*$scale, $ccenter['Y']*$scale, $circle['radius']*$scale, $startAngle, $endAngle, '#FF0000');

        $arc = "GCODE:\n".$arc."\n---------------";
        $line += substr_count($arc, "\n")-1;
        echo writeOnCanvas($id, $arc, 5, $height-($line*15));

        // $pc = print_r($circle,true);
        // $line += substr_count($pc, "\n")-1;
        // echo writeOnCanvas($id, '$circle = '.$pc, 5, $height-($line*9.1), 8);
    }
}

/**
 * Creates a HTML5 canvas element
 * @param  string  $id     ID for the canvas
 * @param  integer $width  width of the canvas
 * @param  integer $height height of the canvas
 * @return string          The canvas
 */
function createCanvas($id, $width = 560, $height = 500)
{
    return "<canvas id='$id'  width='$width' height='$height'></canvas>".PHP_EOL;
}

/**
 * Draw some gcode points on the canvas.
 * @param  string $id    ID of the canvas to draw on
 * @param  array  $lines Array of gcode instructions
 * @return string        The command
 */
function drawCanvasPoints($id, $lines)
{
    $return = '';
    foreach($lines as $num => $line){
        $return .= "<script>".PHP_EOL;
        if($num > 0){
            $return .= "gcodePlotLine('".$id."', {x:".$lines[$num-1]["X"].",y:".$lines[$num-1]["Y"]."},{x:".$line["X"].",y:".$line["Y"]."});".PHP_EOL;
        }
        $return .= "gcodePlotPoint('".$id."', {x:".$line["X"].",y:".$line["Y"]."});".PHP_EOL;
        $return .= "</script>".PHP_EOL;
    }
    return $return;
}

function writeOnCanvas($id, $text, $x = 0, $y = 0, $size = 12, $color = '#000000', $center='false')
{
    $x = intval($x);
    $y = intval($y);
    $return = '<script>'.PHP_EOL;
    $text = explode("\n", $text);
    foreach($text as $t){
        $return .= "gcodeWrite('$id', '$t', $x, $y, $size, '$color', $center);\n";
        $y += $size;
    }
    return $return."</script>".PHP_EOL;
}

function pointOnCanvas($id, $x = 0, $y = 0)
{
    $x = intval($x);
    $y = intval($y);
    return "<script>gcodePlotPoint('$id', {x:$x,y:$y});</script>".PHP_EOL;
}

function circleOnCanvas($id, $x = 0, $y = 0, $r = 1, $color = '#000000')
{
    $x = intval($x);
    $y = intval($y);
    $r = floatval($r);
    return "<script>gcodePlotCircle('$id', {x:$x,y:$y}, $r, '$color');</script>".PHP_EOL;
}

function arcOnCanvas($id, $x = 0, $y = 0, $r = 1, $startAngle = 0, $endAngle = M_PI, $color = '#000000')
{
    $x = intval($x);
    $y = intval($y);
    $r = floatval($r);
    $startAngle = floatval($startAngle);
    $endAngle = floatval($endAngle);
    return "<script>gcodePlotArc('$id', {x:$x,y:$y,sAngle:$startAngle,eAngle:$endAngle}, $r, '$color');</script>".PHP_EOL;
}

/**
 * @deprecated
 * @param  [type] $buffer [description]
 * @param  string $id     [description]
 * @return [type]         [description]
 */
function processBuffer($buffer, $id = 'gcodeview')
{
    global $pos_error;
    global $debug;

    $lines  = getLines($buffer);
    $circle = getCircle($lines);

    if($circle == false){
        return false;
    }
    if(max($circle['errors']) < $pos_error){
        return $circle;
    } else {
        return false;
    }
    //print_r($lines);
}

/**
 * Creates a comment in our output
 * @deprecated
 * @param  [type] $contents [description]
 * @param  [type] $comment  [description]
 * @return [type]           [description]
 */
function createComment($contents, $comment)
{
    return sprintf("<span class='token' data-toggle=\"tooltip\" data-placement=\"top\" title='%s'>%s</span>", htmlentities($comment), htmlentities($contents));
}

/**
 * @deprecated
 * @param  [type] $part [description]
 * @return [type]       [description]
 */
function processPart($part)
{
    global $definitions;
    $part = trim($part);
    $default = array('comment' => '');

    if (isset($definitions[$part])) {
        $default['comment'] = $definitions[$part]->definition;
    }

    return $default;
}
