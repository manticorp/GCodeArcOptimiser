<?php

$debug             = isset($_GET['debug']) ? filter_var($_GET['debug'] ,FILTER_VALIDATE_BOOLEAN) : false;
$lookahead         = 5;   //
$pos_error         = 0.1; // absolute
$alignment_error   = 0.01; // absolute
$extrusion_error   = 0.15; // percent

define('EOL', "<br />");

$outFile = './output.gcode';

include "functions.php";

$errorFiles = array();
if (!empty($_FILES) && !empty($_FILES['upload'])) {
    foreach($_FILES['upload']['name'] as $key => $fn) {
        if($_FILES['upload']['error'][$key] !== 0){
            $errorFiles[] = $fn;
            continue;
        } else {
            $filename = realpath("./inputs/").DIRECTORY_SEPARATOR.$fn;
            if (
                !is_uploaded_file($_FILES['upload']['tmp_name'][$key]) or
                !copy($_FILES['upload']['tmp_name'][$key], $filename))
            {
                $error = "Could not  save file as $filename!";
                exit();
            }
        }
    }
}

$files = array_merge(glob('./inputs/*.gcode'),glob('./inputs/*.g'));

if(isset($_GET['delete']) && file_exists($_GET['delete']) && in_array($_GET['delete'], $files)) {
    unlink($_GET['delete']);
    unset($files[array_search($_GET['delete'], $files)]);
}

if(isset($_GET['file'])) {
    $input = $_GET['file'];

    $gcode  = str_replace("\r","",file_get_contents($input));
    $gcode  = $gcodeArray = explode("\n", $gcode);
    $gcode  = SplFixedArray::fromArray($gcode);
}

?>

<!DOCTYPE html>
<html lang="">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>GCode Decoder</title>

        <!-- Bootstrap CSS -->
        <link href="//netdna.bootstrapcdn.com/bootstrap/3.2.0/css/bootstrap.min.css" rel="stylesheet">

        <style>
        body {
            padding-bottom: 200px;
        }
        .comment {
            color: green;
        }
        .token {
            cursor: pointer;
        }
        .btn-file {
            position: relative;
            overflow: hidden;
        }
        .btn-file input[type=file] {
            position: absolute;
            top: 0;
            right: 0;
            min-width: 100%;
            min-height: 100%;
            font-size: 100px;
            text-align: right;
            filter: alpha(opacity=0);
            opacity: 0;
            outline: none;
            background: white;
            cursor: inherit;
            display: block;
        }
        .deletelink {
            color: red;
        }
        .deletelink:hover {
            color: #a00;
        }
        canvas { border: 1px solid black; }
        </style>

        <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
        <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
        <!--[if lt IE 9]>
            <script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
            <script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
        <![endif]-->

        <script type="text/javascript" src="gcodeView.js"></script>
    </head>
    <body>

        <h1 class="text-center">GCode Decoder</h1>
        <div class="container">
            <div class="row">
                <div class="col-sm-12">
                    <form action="index.php" method="POST" class="form-horizontal" role="form" enctype="multipart/form-data">
                            <div class="form-group">
                                <legend>Upload File</legend>
                            </div>

                            <div class="form-group">
                                <div class="input-group">
                                    <span class="input-group-btn">
                                        <span class="btn btn-primary btn-file">
                                            Browse&hellip; <input type="file" id="upload" name="upload[]" multiple>
                                        </span>
                                    </span>
                                    <input type="text" class="form-control" readonly>
                                </div>
                                <p class="help-block">.gcode and .g files only.</p>
                            </div>

                            <div class="form-group">
                                <div class="col-sm-10 col-sm-offset-2">
                                    <button type="submit" class="btn btn-primary">Submit</button>
                                </div>
                            </div>
                    </form>
                </div>
            </div>
            <div class="row">
                <div class="col-xs-12 col-sm-12 col-md-12 col-lg-12">
<?php if(!empty($files)): ?>
                    <ul class="file-list">
                    <?php foreach($files as $file): ?>
                        <li><a href="?file=<?php echo $file; ?>"><?php echo basename($file); ?></a> <a class="deletelink" href="?delete=<?php echo $file; ?>"><span class="glyphicon glyphicon-remove" aria-hidden="true"></span></a></li>
                    <?php endforeach; ?>
                    </ul>
<?php endif; ?>
                </div>
            </div>
        </div>

<?php if(isset($_GET['file'])): ?>
        <?php
        $start = microtime(true);
        printf("Total Lines: %d | ", $gcode->count());
        ?>
        <hr/>
        <?php
        $output = processGcode($gcode);
        ?>
        <?php file_put_contents($outFile, $output); ?>
        <?php
        printf("\nTotal Output Lines %d   |  Took %.4fs   | Peak Memory: %s\n", substr_count($output,"\n"), microtime(true)-$start, human_byte_convert(memory_get_peak_usage()));
        ?>
<?php elseif(empty($files)): ?>
    <h2>No Files Found</h2>
<?php endif; ?>

        <!-- jQuery -->
        <script src="//code.jquery.com/jquery.js"></script>
        <!-- Bootstrap JavaScript -->
        <script src="//netdna.bootstrapcdn.com/bootstrap/3.2.0/js/bootstrap.min.js"></script>

        <script>
        $(function () {
          $('[data-toggle="tooltip"]').tooltip();
        });
        $(document).on('change', '.btn-file :file', function() {
            var input = $(this),
                numFiles = input.get(0).files ? input.get(0).files.length : 1,
                label = input.val().replace(/\\/g, '/').replace(/.*\//, '');
            input.trigger('fileselect', [numFiles, label]);
        });
        $(document).ready( function() {
            $('.btn-file :file').on('fileselect', function(event, numFiles, label) {

                var input = $(this).parents('.input-group').find(':text'),
                    log = numFiles > 1 ? numFiles + ' files selected' : label;

                if( input.length ) {
                    input.val(log);
                } else {
                    if( log ) alert(log);
                }

            });
        });
        </script>
    </body>
</html>