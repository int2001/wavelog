function ExportClublog(station_id) {
	if ($(".alert").length > 0) {
		$(".alert").remove();
	}
	if ($(".errormessages").length > 0) {
		$(".errormessages").remove();
	}
	$(".ld-ext-right-"+station_id).addClass('running');
	$(".ld-ext-right-"+station_id).prop('disabled', true);

	$.ajax({
		url: base_url + 'index.php/clublog/uploadlog',
		type: 'post',
		data: {'station_id': station_id},
		success: function (data) {
			$(".ld-ext-right-"+station_id).removeClass('running');
			$(".ld-ext-right-"+station_id).prop('disabled', false);
			if (data.status == 'OK') {
				$.each(data.info, function(index, value){
					$('#modcount'+value.station_id).html(value.modcount);
					$('#notcount'+value.station_id).html(value.notcount);
					$('#totcount'+value.station_id).html(value.totcount);
				});
				$("#export").append('<div class="alert alert-success" role="alert">' + data.infomessage + '</div>');
			}
			else {
				$("#export").append('<div class="alert alert-danger" role="alert">' + data.info + '</div>');
			}

			if (data.errormessages.length > 0) {
				$("#clublog_export").append(
					'<div class="errormessages">\n' +
					'    <div class="card mt-2">\n' +
					'        <div class="card-header bg-danger">\n' +
					'            Error Message\n' +
					'        </div>\n' +
					'        <div class="card-body">\n' +
					'            <div class="errors"></div>\n' +
					'        </div>\n' +
					'    </div>\n' +
					'</div>'
				);
				$.each(data.errormessages, function (index, value) {
					$(".errors").append('<li>' + value);
				});
			}
		}
	});
}
