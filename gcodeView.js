function gcodePlotLine(id, point1, point2){
    var canvas = document.getElementById(id);
    var ctx = canvas.getContext('2d');
    ctx.beginPath();
    ctx.moveTo(point1.x,point1.y);
    ctx.lineTo(point2.x,point2.y);
    ctx.stroke();
}

function gcodePlotPoint(id, point){
    var canvas = document.getElementById(id);
    var ctx = canvas.getContext('2d');
    ctx.beginPath();
    ctx.arc(point.x, point.y, 2, 0, 2 * Math.PI);
    ctx.fill();
}

function gcodePlotCircle(id, point, radius, color){
    color = color || '#000000';
    var canvas = document.getElementById(id);
    var ctx = canvas.getContext('2d');
    ctx.beginPath();
    ctx.strokeStyle = color;
    ctx.arc(point.x, point.y, radius, 0, 2 * Math.PI);
    ctx.stroke();
    ctx.strokeStyle = '#000000';
}

function gcodePlotArc(id, point, radius, color){
    console.log(point);
    color = color || '#000000';
    var canvas = document.getElementById(id);
    var ctx = canvas.getContext('2d');
    ctx.beginPath();
    ctx.strokeStyle = color;
    ctx.arc(point.x, point.y, radius, point.sAngle, point.eAngle);
    ctx.stroke();
    ctx.strokeStyle = '#000000';
}

function gcodeWrite(id, text, x, y, size, color, center){
    x = x || 0;
    y = y || 0;
    size = size || 12;
    color = color || '#000000';
    center = center || false;
    var canvas = document.getElementById(id);
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = color;
    ctx.font = size + "px Consolas";
    ctx.textBaseline = "bottom";
    if(center){
        var t = ctx.measureText(text);
        x = x - (t.width/2);
    }
    ctx.fillText(text, x, y);
    ctx.fillStyle = '#000000';
}