# GCode Optimiser
## Optimises GCode for G2 and G3 arc movements

### Basic Usage

Either use the built in index.php, or use it like this:

```php
$inputFn = 'SomeGcodeFile.g';

$gcode  = str_replace("\r","",file_get_contents($inputFn));
$gcode  = explode("\n", $gcode);
$gcode  = SplFixedArray::fromArray($gcode);

$processed = processGcode($gcode);
```

You could create a CLI file like this:

```php
<?php

$options = getopt('f:o:');

$gcode  = str_replace("\r","",file_get_contents($options['f']));
$gcode  = explode("\n", $gcode);
$gcode  = SplFixedArray::fromArray($gcode);

$processed = processGcode($gcode);

file_put_contents($options['o'], $processed);
```

and then use it like this:

```
shell> php cli.php -f "SomeGcodeFile.g" -o "MyProcessedFile.g"
```

### How it works

This optimiser works by looping through the input gcode file and loading lines into a circular buffer. Every time it loads a new value, it checks to see if the buffer can be described very well by a circle or not.

If it can't be described by a circle very well, it will just dequeue an item on the buffer, queue an item on the buffer and continue onto the next round.

If it CAN be described by a circle, it replaces the appropriate code with the G2/G3 arc, and reloads the buffer.

Simple, really.

### How it detects circles/arcs

This code detects circles/arcs by taking the points on the current buffer and finding the circle of best fit for those points. IFF all those points then lie within a certain distance from that circle, wahey, we've found a circle that validly describes those points. It can then work out the extrusion needed along that line from the G0/G1 commands, and if it's a smooth extrusion along the line, then voila! We have a simple replacement.

The algorithm currently uses a least squares fitting for the circle, which is (relatively) fast, but of course, a lot of GCode files are 10,000's of lines long...so it adds up.

There's also a slightly more complex circle fitting algorithm included, but it's very slow at the moment, and I can't seem to optimise it.

### I want to *really* understand how it works

Then I suggest you look at: https://github.com/manticorp/GCodeArcOptimiser/blob/master/functions.php

If you want to see how it kinda works, the main code flow goes like so:

```
processGcode()
    |
    V
bufferValid()
    |
    V
getCircle()
    |
    V
generateGcodeArc()

```

Just look at each of those functions in functions.php