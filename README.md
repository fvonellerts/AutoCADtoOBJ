# AutoCADtoOBJ
PHP script to extract geometry as 3D .obj file from a AutoCAD file using the Autodesk Forge API

## About
Basicly the code from [Autodesk's tutorial](https://forge.autodesk.com/en/docs/model-derivative/v2/tutorials/extract-geometry-from-source-file/), pakaged into a single PHP script to be run from a webserver.
Also does some error reporting and provides a download link, if the conversion has been successful.

## Installation
This script uses the Autodesk Forge API, so you need to [register or login](https://forge.autodesk.com/) first and then [create an app](https://forge.autodesk.com/en/docs/oauth/v2/tutorials/create-app/). Insert your app's client ID and secret into the config section of the PHP script and you are good to go!
