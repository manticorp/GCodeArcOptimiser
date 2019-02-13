<?php

class GcodeArcOptimiser {
    protected $debug           = false;
    protected $lookahead       = 10;
    protected $posError        = 0.1;  // absolute
    protected $alignmentError  = 0.01; // absolute
    protected $extrusionError  = 0.15; // percent

    public function __construct(
        $lookahead      = 10,
        $posError       = 0.1,
        $alignmentError = 0.01,
        $extrusionError = 0.15,
        $debug          = false
    ) {
        $this->setLookahead($lookahead);
        $this->setPosError($posError);
        $this->setAlignmentError($alignmentError);
        $this->setExtrusionError($extrusionError);
        $this->setDebug($debug);
        return $this;
    }

    public function process($gcode)
    {
        $lookahead = $this->getLookahead();
        $debug = $this->getDebug();
        $start = microtime(true);

        // Keeps track of the amount of valid buffers (valid circles)
        $totalValidBuffers = 0;
        // The output gcode
        $output = "";
        // The looping buffer
        $buffer = new SplQueue();

        // go back to the start of our gcode
        $gcode->rewind();

        $totalInputLines = $gcode->count();

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
            if((microtime(true) - $start) > 120  || ($debug === true && $totalValidBuffers > 1000)){
                $output .= $gcode->current()."\n"; continue;
            }
            // check to see if we have a 'valid' buffer
            $valid = $this->bufferValid($buffer);
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
                        $lines = $this->getLines($buffer);
                        // Generate a gcode arc from the lines
                        $output .= $this->generateGcodeArc($processed, $lines)."\n";
                    } else {
                        // otherwise we just stick the buffer on the output.
                        foreach($buffer as $num => $line){
                            $output .= $line."\n";
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

        return [
            'gcode'              => $output,
            'time'               => microtime(true) - $start,
            'total_replacements' => $totalValidBuffers,
            'input_lines'        => $totalInputLines,
            'output_lines'       => count(explode("\n", $output)),
        ];
    }


    /**
     * Generates a G2/G3 gcode arc given the describing circle and the lines used to obtain it.
     * @param  array  $circle The circle description
     * @param  array  $lines  The lines used to describe it
     * @return string         The resulting GCode
     */
    public function generateGcodeArc($circle, $lines)
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
    public function bufferValid($buffer)
    {
        for($buffer->rewind();$buffer->valid();$buffer->next()){
            if(substr($buffer->current(), 0, 2) !== "G1"){
                $buffer->rewind();
                return false;
            } else if(strpos($buffer->current(), "Z") !== FALSE) {
                $buffer->rewind();
                return false;
            }
        }
        $lines = $this->getLines($buffer);
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
            $extrusions = $this->getExtrusionLengths($lines);
            $eerror = $this->calculateExtrusionError($extrusions);
            if($this->calculateExtrusionError($extrusions) === false){
                $buffer->rewind();
                return false;
            }
        }
        $lines->rewind();
        $circle = $this->getCircle($lines);
        if($circle === false) {
            $buffer->rewind();
            return false;
        }
        if(max($circle['errors']) > $this->getPosError()){
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
    public function calculateExtrusionError($extrusions)
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
    public function getExtrusionLengths($lines)
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
                $ls = $this->vector_subtract($line, $prev);
                $lsLength = max($this->vector_magnitude($ls),0.0000001);
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
    public function getLines($buffer)
    {
        $lines = new SplFixedArray($buffer->count());
        for($buffer->rewind();$buffer->valid();$buffer->next()){
            $lines[$buffer->key()] = $this->getCoords($buffer->current());
        }
        return $lines;
    }

    /**
     * Finds the circle describing the given lines
     * @param  array $lines The gcode lines
     * @return array        The resulting descriptive circle
     */
    public function getCircle($lines)
    {
        $center = $this->getCircleCenterLeastSquares($lines);

        if (!$center) {
            return false;
        }

        // $center = getCircleCenter($lines);
        // if($center === false) return false;
        // $radius = getCircleRadius($center, $lines);
        $errors = $this->getCircleErrors($center, $center['R'], $lines);
        $direction = $this->getCircleDirection($lines);

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
    public function getCircleCenterLeastSquares($lines)
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

        if ($Suu == 0) {
            return false;
        }

        $denominator = (((-($Suv*$Suv))/$Suu)+$Svv);

        if ($denominator == 0) {
            return false;
        }

        $v = ((($Svvv+$Svuu)/2) - (($Suv/2)*(($Suuu+$Suvv)/$Suu)))/$denominator;
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
    public function getCircleErrors($center, $radius, $lines)
    {
        $errors = array();
        foreach($lines as $line) {
            $vec = array($center['X'] - $line['X'], $center['Y']-$line['Y']);
            $length = $this->vector_magnitude($vec);
            $errors[] = abs($length-$radius);
        }
        return $errors;
    }

    /**
     * Calculates whether the vectors are clockwise or anticlockwise
     * @param  array  $lines The gcode lines
     * @return string        The chirality
     */
    public function getCircleDirection($lines)
    {
        $edge1 = $this->vector_subtract($lines[1], $lines[0]);
        $edge2 = $this->vector_subtract($lines[2], $lines[1]);
        $mag = $this->vector_magnitude_cross_product($edge1, $edge2);
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
    public function getCircleRadius($center, $lines)
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
    public function getCircleCenter($lines)
    {
        $ae = $this->getAlignmentError();
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
                    if(abs($delta) > $ae) {
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
    public function getCoords($line)
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
    public function angleBetween($v1, $v2) {
        $angle = acos( $this->vector_dot_product($v1, $v2) / ($this->vector_magnitude($v1) * $this->vector_magnitude($v2)))  + (3*(M_PI/4));
        return ($angle > M_PI) ? $angle - M_PI : $angle;
    }

    public function vector_dot_product($vector1, $vector2)
    {
        return (($vector1['X'] * $vector2['X']) + ($vector1['Y'] * $vector2['Y']));
    }

    /**
     * Calculates the magnitude of a vector.
     * @param  array $vector The vector array
     * @return float         The magnitude of the vector
     */
    public function vector_magnitude($vector)
    {
        return sqrt(array_reduce($vector, function($carry, $item){ return $carry + pow($item,2);}, 0));
    }

    /**
     * Adds two vectors.
     * @param  array $vector1 The first  vector array
     * @param  array $vector2 The second vector array
     * @return array          The resultant vector
     */
    public function vector_add($vector1, $vector2)
    {
        return array('X' => $vector1['X']+$vector2['X'], 'Y' => $vector1['Y']+$vector2['Y']);
    }

    /**
     * Subtracts two vectors.
     * @param  array $vector1 The first  vector array
     * @param  array $vector2 The second vector array
     * @return array          The resultant vector
     */
    public function vector_subtract($vector1, $vector2)
    {
        return array('X' => $vector1['X']-$vector2['X'], 'Y' => $vector1['Y']-$vector2['Y']);
    }

    /**
     * Subtracts two vectors absolutely (the resultant vector will always be positive X and Y).
     * @param  array $vector1 The first  vector array
     * @param  array $vector2 The second vector array
     * @return array          The resultant vector
     */
    public function vector_abs_subtract($vector1, $vector2)
    {
        return array('X' => abs($vector1['X']-$vector2['X']), 'Y' => abs($vector1['Y']-$vector2['Y']));
    }

    public function vector_magnitude_cross_product($vector1, $vector2)
    {
        return ($vector1['X']*$vector2['Y']) - ($vector1['Y']*$vector2['X']);
    }

    /**
     * Parses gcode string
     * @param  SPLFixedArray $gcode The gcode as an SPLFixedArray of lines
     * @return array                An array of lines where each entry is split into command and parameters
     */
    public function parseGcode($gcode)
    {
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
    public function parseLine($line, $skipNonMoves = true)
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
     * @return mixed
     */
    public function getDebug()
    {
        return $this->debug;
    }

    /**
     * @param mixed $debug
     *
     * @return self
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getLookahead()
    {
        return $this->lookahead;
    }

    /**
     * @param mixed $lookahead
     *
     * @return self
     */
    public function setLookahead($lookahead)
    {
        $this->lookahead = $lookahead;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getPosError()
    {
        return $this->posError;
    }

    /**
     * @param mixed $posError
     *
     * @return self
     */
    public function setPosError($posError)
    {
        $this->posError = $posError;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getAlignmentError()
    {
        return $this->alignmentError;
    }

    /**
     * @param mixed $alignmentError
     *
     * @return self
     */
    public function setAlignmentError($alignmentError)
    {
        $this->alignmentError = $alignmentError;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getExtrusionError()
    {
        return $this->extrusionError;
    }

    /**
     * @param mixed $extrusionError
     *
     * @return self
     */
    public function setExtrusionError($extrusionError)
    {
        $this->extrusionError = $extrusionError;

        return $this;
    }
}