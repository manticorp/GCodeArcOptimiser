<?php
$files = array_merge(glob('./inputs/*.gcode'),glob('./inputs/*.g'));
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
        .chit {
            display: inline-block;
            width: 1.2em;
            height: 1.2em;
            margin-right: 0.5em;
        }
        .colours-explained {
            display: flex;
        }
        .colours-explained .part {
            margin-right: 1em;
        }
        canvas { border: 1px solid black; }
        </style>

        <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
        <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
        <!--[if lt IE 9]>
            <script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
            <script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
        <![endif]-->

    

        <script src="js/three.js"></script>
        <script src="js/controls/OrbitControls.js"></script>
        <script src="js/loaders/GCodeLoader.js"></script>
        <!-- jQuery -->
        <script src="//code.jquery.com/jquery.js"></script>
        <!-- Bootstrap JavaScript -->
        <script src="//netdna.bootstrapcdn.com/bootstrap/3.2.0/js/bootstrap.min.js"></script>
    </head>
    <body>

        <h1 class="text-center">GCode Decoder</h1>
        <div class="container">
            <div class="row">
                <div class="col-sm-12">
                    <form id="the-file-form" action="process.php" method="POST" class="form-horizontal" role="form" enctype="multipart/form-data">
                            <div class="form-group">
                                <legend>Upload File</legend>
                            </div>

                            <div class="form-group">
                                <div class="input-group">
                                    <span class="input-group-btn">
                                        <span class="btn btn-primary btn-file">
                                            Browse&hellip; <input type="file" id="upload" name="upload" accept=".g,.gcode">
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
        </div>
        <div id="result-stats">
        </div>
        <div class="colours-explained">
            <div class="part"><span class="chit" style="background-color: #F00;"></span> Red = Movement</div>
            <div class="part"><span class="chit" style="background-color: #0F0;"></span> Green = G1 Line Extrusion</div>
            <div class="part"><span class="chit" style="background-color: #00F;"></span> Blue = G2 & G3 Arc Extrusion</div>
        </div>
        <div style="display:flex">
            <div style="width: calc(50% - 8px);">
                <h1>Input</h1>
                <div id="input" style="width: 100%; height: 500px;"></div>
            </div>
            <div style="margin-left: 16px; width: calc(50% - 8px);">
                <h1>Output</h1>
                <div id="output" style="width: 100%; height: 500px;"></div>
            </div>
        </div>

        <script>
        (function() {
             function fitCameraToObject( camera, object, offset, controls, scene ) {

                offset = offset || 1.25;

                const boundingBox = new THREE.Box3();

                // get bounding box of object - this will be used to setup controls and camera
                boundingBox.setFromObject( object );
                    
                        //ERRORS HERE
                let center = new THREE.Vector3();
                boundingBox.getCenter(center);
                let size = new THREE.Vector3();
                boundingBox.getSize(size);

                // get the max side of the bounding box (fits to width OR height as needed )
                const maxDim = Math.max( size.x, size.y, size.z );
                const fov = camera.fov * ( Math.PI / 180 );
                cameraZ = Math.abs( maxDim / 2 * Math.tan( fov * 2 ) ); //Applied fifonik correction

                cameraZ *= offset; // zoom out a little so that objects don't fill the screen

                // <--- NEW CODE
                //Method 1 to get object's world position
                scene.updateMatrixWorld(); //Update world positions
                var objectWorldPosition = new THREE.Vector3();
                objectWorldPosition.setFromMatrixPosition( object.matrixWorld );
                
                //Method 2 to get object's world position
                //objectWorldPosition = object.getWorldPosition();

                const directionVector = camera.position.sub(objectWorldPosition);     //Get vector from camera to object
                const unitDirectionVector = directionVector.normalize(); // Convert to unit vector
                camera.position = unitDirectionVector.multiplyScalar(cameraZ); //Multiply unit vector times cameraZ distance
                camera.lookAt(objectWorldPosition); //Look at object
                // --->

                const minZ = boundingBox.min.z;
                const cameraToFarEdge = ( minZ < 0 ) ? -minZ + cameraZ : cameraZ - minZ;

                camera.far = cameraToFarEdge * 3;
                camera.updateProjectionMatrix();

                if ( controls ) {

                  // set camera to rotate around center of loaded object
                  controls.target = center;

                  // prevent camera from zooming out far enough to create far plane cutoff
                  controls.maxDistance = cameraToFarEdge * 2;
                         // ERROR HERE    
                  controls.saveState();

                } else {

                    camera.lookAt( center )

               }
            }
            function displayGcode (container, gcode) {
                $(container).empty();
                var camera, scene, renderer, controls;
                var w = $(container).width(), h = $(container).height();
                function init() {
                    camera = new THREE.PerspectiveCamera( 60, container.clientWidth / container.clientHeight, 0.1, 10000 );
                    camera.position.set( 0, 0, 50 );
                    controls = new THREE.OrbitControls( camera , container);
                    scene = new THREE.Scene();
                    var loader = new THREE.GCodeLoader();
                    var object = loader.parse(gcode);
                    object.position.set( 0, 0, 0 );
                    scene.add( object );
                    renderer = new THREE.WebGLRenderer();
                    renderer.setPixelRatio( window.devicePixelRatio );
                    renderer.setSize(w, h);
                    container.appendChild( renderer.domElement );
                    fitCameraToObject(camera, object, null, controls, scene);
                }
                function animate() {
                    renderer.render( scene, camera );
                    controls.update();
                    requestAnimationFrame( animate );
                }
                init();
                animate();
            }
            $(document).on('change', '.btn-file :file', function() {
                var input = $(this),
                    numFiles = input.get(0).files ? input.get(0).files.length : 1,
                    label = input.val().replace(/\\/g, '/').replace(/.*\//, '');
                input.trigger('fileselect', [numFiles, label]);
            });
            $(document).ready( function() {
                $('[data-toggle="tooltip"]').tooltip();
                $('.btn-file :file').on('fileselect', function(event, numFiles, label) {

                    var input = $(this).parents('.input-group').find(':text'),
                        log = numFiles > 1 ? numFiles + ' files selected' : label;

                    if( input.length ) {
                        input.val(log);
                    } else {
                        if( log ) alert(log);
                    }

                });
                $("#the-file-form").on('submit', function(e) {
                    var form = $(this);
                    var url = form.attr('action');
                    var formData = new FormData();
                    formData.append("upload", $('#upload').get(0).files[0]);

                    var request = new XMLHttpRequest();
                    request.open("POST", url, true);

                    request.addEventListener('load', function(e) {
                        if (request.status === 200) {
                            result = JSON.parse(this.responseText);
                            displayGcode(document.getElementById('input'), result.original);
                            displayGcode(document.getElementById('output'), result.optimised);
                            $('#result-stats').empty().append('<pre>'+
                                '        Time Taken : ' + result.time + "\n" +
                                ' Total Input Lines : ' + result.input_lines + "\n" +
                                'Total Output Lines : ' + result.output_lines + "\n" +
                                'Total Replacements : ' + result.total_replacements + "\n" +
                            '</pre>');
                        } else if (request.status > 200) {
                            console.warn(e, this.responseText);
                        }
                    });

                    request.addEventListener('error', function(e) {
                        console.log(e);
                    });

                    request.send(formData);
                    e.preventDefault();
                    return false;
                });
            });
        })();
        </script>
    </body>
</html>