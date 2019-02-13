# GCode Optimiser
## Optimises GCode for G2 and G3 arc movements

![Example Output](https://github.com/manticorp/GCodeArcOptimiser/blob/master/example-result.png?raw=true)

### Basic Usage

Either use the built in index.php, or use it like this:

```php
include "GCodeArcOptimiser.php";
$inputFn = 'SomeGcodeFile.g';

$gcode  = file_get_contents($inputFn);

$optimiser = new GCodeArcOptimiser();
$processed = $optimiser->process($gcode);
```

You could create a CLI file like this:

```php
<?php
include "GCodeArcOptimiser.php";

$options = getopt('f:o:');

$gcode  = file_get_contents($options['f']);

$optimiser = new GCodeArcOptimiser();
$processed = $optimiser->process($gcode);

file_put_contents($options['o'], $processed['gcode']);
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

Then I suggest you look at: https://github.com/manticorp/GCodeArcOptimiser/blob/master/GCodeArcOptimiser.php

If you want to see how it kinda works, the main code flow goes like so:

```
process()
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

Just look at each of those functions in GCodeArcOptimiser.php

### Example output

![Example Output](https://github.com/manticorp/GCodeArcOptimiser/blob/master/example-result.png?raw=true)

In my tests, a circle heavy print (a pawn chess piece) can go from 45,000 instructions to around 14,000

An example of the change it can make:

```
G1 X115.938 Y62.818 E5.73464 F1200.000
G1 X116.919 Y62.911 E5.84105
G1 X117.874 Y63.038 E5.94500
G1 X118.846 Y63.205 E6.05141
G1 X119.789 Y63.405 E6.15536
G1 X120.745 Y63.645 E6.26177
G1 X121.670 Y63.915 E6.36572
G1 X122.606 Y64.226 E6.47213
G1 X123.508 Y64.565 E6.57608
G1 X124.417 Y64.947 E6.68248
G1 X125.290 Y65.353 E6.78644
G1 X126.168 Y65.803 E6.89284
G1 X127.009 Y66.274 E6.99679
G1 X127.850 Y66.788 E7.10320
G1 X128.652 Y67.322 E7.20716
G1 X129.452 Y67.898 E7.31356
G1 X130.212 Y68.491 E7.41751
G1 X130.966 Y69.126 E7.52392
G1 X131.679 Y69.775 E7.62787
G1 X132.383 Y70.465 E7.73428
G1 X133.044 Y71.166 E7.83823
G1 X133.507 Y71.694 E7.91402
G1 X134.301 Y72.656 E8.04857
G1 X134.892 Y73.445 E8.15498
G1 X135.441 Y74.237 E8.25893
G1 X135.971 Y75.068 E8.36534
G1 X136.458 Y75.900 E8.46929
G1 X136.924 Y76.769 E8.57570
G1 X137.347 Y77.635 E8.67964
G1 X137.746 Y78.537 E8.78605
G1 X138.102 Y79.432 E8.89001
G1 X138.431 Y80.361 E8.99641
G1 X138.718 Y81.281 E9.10036
G1 X138.977 Y82.233 E9.20677
G1 X139.194 Y83.171 E9.31072
G1 X139.379 Y84.140 E9.41713
G1 X139.524 Y85.092 E9.52108
G1 X139.636 Y86.072 E9.62749
G1 X139.709 Y87.033 E9.73144
G1 X139.746 Y88.018 E9.83784
G1 X139.746 Y88.982 E9.94180
G1 X139.709 Y89.967 E10.04821
G1 X139.636 Y90.928 E10.15215
G1 X139.524 Y91.908 E10.25856
G1 X139.379 Y92.860 E10.36252
G1 X139.194 Y93.829 E10.46892
G1 X138.977 Y94.767 E10.57287
G1 X138.718 Y95.719 E10.67928
G1 X138.431 Y96.639 E10.78323
G1 X138.102 Y97.568 E10.88964
G1 X137.746 Y98.463 E10.99359
G1 X137.347 Y99.365 E11.10000
G1 X136.924 Y100.231 E11.20395
G1 X136.458 Y101.100 E11.31036
G1 X135.971 Y101.932 E11.41431
G1 X135.441 Y102.763 E11.52072
G1 X134.892 Y103.555 E11.62466
G1 X134.301 Y104.344 E11.73107
G1 X133.694 Y105.092 E11.83503
G1 X133.044 Y105.834 E11.94143
G1 X132.383 Y106.535 E12.04538
G1 X131.679 Y107.225 E12.15179
G1 X130.966 Y107.874 E12.25574
G1 X130.212 Y108.509 E12.36215
G1 X129.452 Y109.102 E12.46610
G1 X128.652 Y109.678 E12.57250
G1 X127.850 Y110.212 E12.67646
G1 X127.009 Y110.726 E12.78287
G1 X126.168 Y111.197 E12.88682
G1 X125.290 Y111.647 E12.99322
G1 X124.417 Y112.053 E13.09718
G1 X123.508 Y112.435 E13.20358
G1 X122.606 Y112.774 E13.30754
G1 X121.670 Y113.085 E13.41394
G1 X120.745 Y113.355 E13.51789
G1 X119.789 Y113.595 E13.62430
G1 X118.846 Y113.795 E13.72825
G1 X117.874 Y113.962 E13.83466
G1 X116.919 Y114.089 E13.93861
G1 X115.938 Y114.182 E14.04502
G1 X114.976 Y114.237 E14.14897
G1 X113.990 Y114.255 E14.25538
G1 X113.026 Y114.237 E14.35933
G1 X112.042 Y114.181 E14.46573
G1 X111.083 Y114.090 E14.56969
G1 X110.105 Y113.960 E14.67609
G1 X109.156 Y113.797 E14.78004
G1 X108.191 Y113.593 E14.88645
G1 X107.256 Y113.358 E14.99040
G1 X106.310 Y113.082 E15.09681
G1 X105.396 Y112.777 E15.20076
G1 X104.738 Y112.530 E15.27655
G1 X103.584 Y112.057 E15.41110
G1 X102.690 Y111.642 E15.51751
G1 X101.832 Y111.203 E15.62146
G1 X100.972 Y110.720 E15.72787
G1 X100.150 Y110.218 E15.83182
G1 X99.329 Y109.672 E15.93822
G1 X98.547 Y109.108 E16.04218
G1 X97.770 Y108.502 E16.14859
G1 X97.033 Y107.881 E16.25254
G1 X96.304 Y107.218 E16.35895
G1 X95.616 Y106.543 E16.46289
G1 X95.134 Y106.032 E16.53868
G1 X94.304 Y105.101 E16.67324
G1 X93.683 Y104.335 E16.77964
G1 X93.105 Y103.564 E16.88359
G1 X92.543 Y102.754 E16.99000
G1 X92.025 Y101.941 E17.09395
G1 X91.670 Y101.335 E17.16974
G1 X91.072 Y100.241 E17.30430
G1 X90.639 Y99.355 E17.41070
G1 X90.249 Y98.474 E17.51465
G1 X89.885 Y97.558 E17.62106
G1 X89.563 Y96.649 E17.72501
G1 X89.269 Y95.708 E17.83142
G1 X89.017 Y94.778 E17.93537
G1 X88.795 Y93.818 E18.04178
G1 X88.614 Y92.871 E18.14573
G1 X88.465 Y91.896 E18.25214
G1 X88.356 Y90.939 E18.35609
G1 X88.281 Y89.956 E18.46250
G1 X88.245 Y88.993 E18.56645
G1 X88.245 Y88.007 E18.67285
G1 X88.281 Y87.044 E18.77680
G1 X88.356 Y86.061 E18.88322
G1 X88.465 Y85.104 E18.98716
G1 X88.571 Y84.409 E19.06295
G1 X88.795 Y83.183 E19.19750
G1 X89.017 Y82.222 E19.30392
G1 X89.269 Y81.292 E19.40786
G1 X89.563 Y80.351 E19.51427
G1 X89.885 Y79.442 E19.61822
G1 X90.249 Y78.526 E19.72463
G1 X90.639 Y77.645 E19.82858
G1 X91.072 Y76.759 E19.93499
G1 X91.527 Y75.910 E20.03894
G1 X92.025 Y75.059 E20.14535
G1 X92.543 Y74.246 E20.24930
G1 X93.105 Y73.436 E20.35570
G1 X93.651 Y72.707 E20.45399
G1 E17.45399 F12000.00000
```

becomes:

```
G1 X115.938 Y62.818 E5.73464 F1200.000
G1 X116.919 Y62.911 E5.841
G3 X93.651 Y72.707 I-2.924 J25.589 E20.454
G1 E17.45399 F12000.00000
```
