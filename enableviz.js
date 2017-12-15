$(document).ready(function(){
    $("div.graphviz").each(function(index){
    	var apiUrl = mw.config.get('wgScriptPath') + '/api.php';
    	var divBlock = $(this)
    	var dotcode = $(this).text()
    	divBlock.html('<p>Rendering graph...</p><div class="progressbar"></div>');
    	$("div.progressbar").progressbar({'value':false});
    	setTimeout(function(){
	    	try {
	    		svgcode = Viz(dotcode,"svg");
	    		divBlock.html(svgcode);
	    	}
	    	catch(err) {
	    		// the graph might have been too big for the js version to handle
	    		// fall back to the web service
	    		// Using jQuery
	    		$.ajax( {
	    		    url: apiUrl,
	    		    data: {'action':'graphvizservice',
	    		    		'format':'json',
	    		    		'dotcode':dotcode},
	    		    dataType: 'json',
	    		    type: 'POST',
	    		    headers: { 'Api-User-Agent': 'Example/1.0' },
	    		    success: function (result) {
	    		    	divBlock.html(result.graphvizservice.svgcode);
	    		    },
	    		    error: function (result) {
	    		    	divBlock.html('<p>Error: graph could not be rendered. DOT code:</p>'+dotcode);
	    		    	console.log(result);
	    		    }
	    		} );
	    	}
    	},0);
    });
});
