
    function populateMonitorPaddockSelect(callback) {
        $.ajax({
            url: "holistic-monitor.php?mode=plist",
            type: 'GET',
            dataType: 'json',
            success: function(json) {
                if (json.error) {
                    alert('Error server reported:'+json.error);
                    return;
                }
                var sel = $("#monitor-pad-select-list");
                $(sel).find('option').remove().end()
                    .append('<option value="" selected>Select Paddock</option>');
                $.each(json.data, function(i, item) {
                    $(sel).append(new Option(item.name, item.id));
                });
                callback();
            },
            error: function(jqXHR, textStatus, errorThrown) {
                alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
            }
        });     // $.ajax
    }

    function addMonitorEvent() {

        populateMonitorPaddockSelect(function() {
            $("#monitor-dialog")
                .next('.ui-dialog-buttonpane')
                .find("button:contains('Delete Event')")
                .css("display", "none");
            $("#monitor-dialog").dialog('open');
        });
    }

    function editMonitorEvent(padid) {

        populateMonitorPaddockSelect(function() {
            $("#monitor-dialog")
                .next('.ui-dialog-buttonpane')
                .find("button:contains('Delete Event')")
                .css("display", "");
            $("#monitor-pad-select-list").val(padid);
            $("#monitor-dialog").dialog('open');
        });
    }

    function updateMonitorDetails() {
        var ndays = $("#lastndays").val();
        alert("Searching for events " + ndays + " days old.");
    }

    function monitorInit() {

        $("#monitor-overview").DataTable({
            dom: 'T<"clear">lfrtip',
            tableTools: {
                sSwfPath: "swf/copy_csv_xls_pdf.swf",
                aButtons: [
                    { sExtends: "copy",  sButtonText: "Copy",
                      mColumns: 'visible' },
                    { sExtends: "csv",   sButtonText: "CSV/Excel",
                      mColumns: 'visible' },
                    { sExtends: "pdf",   sButtonText: "PDF",
                      mColumns: 'visible' ,
                      sPdfOrientation: "landscape",
                      sTitle: "MonitorOverview",
                      sMessage: "Monitor Overview" },
                    { sExtends: "print", sButtonText: "Print",
                      mColumns: 'visible' ,
                      sTitle: "MonitorOverview",
                      sMessage: "<h1>Monitor Overview</h1>" },
                ]
            },
            stateSave: true,
            deferRender: true,
            scrollCollapse: true,
            paging: false,
            processing: true,

            ajax: "./holistic-monitor.php?mode=olist&plan=",

            columnDefs: [
                {
                    targets: [ -1, -2 ],
                    visible: false,
                    searchable: false
                }
            ],
            order: [[ 0, "asc" ]],
            columns: [
                { data: "seq" },
                { data: "name" },
                { data: "mdate" },
                { data: "who" },
                { data: "moisture" },
                { data: "growth" },
                { data: "mada" },
                { data: "ada" },
                { data: "dayssince" },
                { data: "daystilherdin" },
                { data: "daysherdin" },
                { data: "daystilherdout" },
                { data: "daysherdout" },
                { data: "padid" },
                { data: "mid" }
            ],
            rowCallback: function( row, data, index ) {
                var idx;
                if (data.moisture) {
                    var moisture = ['Unknown','Dusty','Dry','Moist','Wet','Soggy'];
                    idx = parseInt(data.moisture);
                    idx = (idx<0 ||idx>5)?0:idx;
                    $('td:eq(4)', row).html( moisture[idx] );
                }
                if (data.growth) {
                    var growth = ['Unknown','None','Slow','Medium','Fast'];
                    idx = parseInt(data.growth);
                    idx = (idx>3 || idx<0)?0:idx+1;
                    $('td:eq(5)', row).html( growth[idx] );
                }
            }
        });

        $("#monitor-details").DataTable({
            dom: 'T<"clear">lfrtip',
            tableTools: {
                sSwfPath: "swf/copy_csv_xls_pdf.swf",
                aButtons: [
                    { sExtends: "copy",  sButtonText: "Copy",
                      mColumns: 'visible' },
                    { sExtends: "csv",   sButtonText: "CSV/Excel",
                      mColumns: 'visible' },
                    { sExtends: "pdf",   sButtonText: "PDF",
                      mColumns: 'visible' ,
                      sPdfOrientation: "landscape",
                      sTitle: "MonitorDetails",
                      sMessage: "Monitor Details" },
                    { sExtends: "print", sButtonText: "Print",
                      mColumns: 'visible' ,
                      sTitle: "MonitorDetails",
                      sMessage: "<h1>Monitor Details</h1>" },
                ]
            },
            stateSave: true,
            deferRender: true,
            scrollCollapse: true,
            paging: false,
            processing: true,

            ajax: "./holistic-monitor.php?mode=dlist",

            columnDefs: [
                {
                    targets: [ -1, -2, -3 ],
                    visible: false,
                    searchable: false
                }
            ],
            order: [[ 0, "asc" ], [ 1, "desc" ]],
            columns: [
                { data: "name" },
                { data: "mdate", className: "dt-center" },
                { data: "moisture", className: "dt-center" },
                { data: "growth", className: "dt-center" },
                { data: "ada", className: "dt-right" },
                { data: "who" },
                { data: "notes" },
                { data: "padid" },
                { data: "id" }
            ],
            rowCallback: function( row, data, index ) {
                var moisture = ['Unknown','Dusty','Dry','Moist','Wet','Soggy'];
                var idx = parseInt(data.moisture);
                idx = (idx<0 ||idx>5)?0:idx;
                $('td:eq(2)', row).html( moisture[idx] );
                var growth = ['Unknown','None','Slow','Medium','Fast'];
                idx = parseInt(data.growth);
                idx = (idx>3 || idx<0)?0:idx+1;
                $('td:eq(3)', row).html( growth[idx] );
            },
            preDrawCallback: function() {
                $("#monitor-details tbody").off('click', 'tr');
            },
            drawCallback: function() {
                $("#monitor-details tbody").on('click', 'tr', function() {
                    var data = $("#monitor-details").DataTable().row(this).data();
                    $("#monitor-id").val(data.id);
                    $("#monitor-date").val(data.mdate);
                    $("#monitor-moisture").val(data.moisture);
                    $("#monitor-growth").val(data.growth);
                    $("#monitor-ada").val(data.ada);
                    $("#monitor-who").val(data.who);
                    $("#monitor-notes").val(data.notes);
                    editMonitorEvent(data.padid);
                });
            }
        });

        $("#monitor-dialog").dialog({
            autoOpen: false,
            modal: true,
            minWidth: 315,
            closeOnEscape: true,
            close: function( event, ui ) {
                $("#monitor-id").val('');
                $("#monitor-date").val('');
                $("#monitor-moisture").val('');
                $("#monitor-growth").val('');
                $("#monitor-ada").val('');
                $("#monitor-who").val('');
                $("#monitor-notes").val('');
            },
            buttons: {
                "Cancel": function() {
                    $(this).dialog("close");
                },
                "Save": function() {
                    var data;
                    var id          = $("#monitor-id").val();
                    var padid       = $("#monitor-pad-select-list").val();
                    var mdate       = $("#monitor-date").val();
                    var moisture    = $("#monitor-moisture").val();
                    var growth      = $("#monitor-growth").val();
                    var ada         = $("#monitor-ada").val();
                    var who         = $("#monitor-who").val();
                    var notes       = $("#monitor-notes").val();
                    if (id === "")
                        data = 'mode=add&padid='+padid+'&date='+mdate+'&moisture='+moisture+'&growth='+growth+'&ada='+ada+'&who='+who+'&notes='+notes;
                    else
                        data = 'mode=update&id='+id+'&padid='+padid+'&date='+mdate+'&moisture='+moisture+'&growth='+growth+'&ada='+ada+'&who='+who+'&notes='+notes;

                    $.ajax({
                        url: 'holistic-monitor.php',
                        type: 'POST',
                        data: data,
                        dataType: 'json',
                        success: function(json) {
                            if (json.error) {
                                alert('Error server reported: '+json.error);
                                return;
                            }
                            $('#monitor-overview').DataTable().ajax.reload(reInitTableTools);
                            $('#monitor-details').DataTable().ajax.reload(reInitTableTools);
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
                        }
                    });
                    $(this).dialog("close");
                },
                "Delete Event": function() {
                    var id = $("#monitor-id").val();
                    $.ajax({
                        url: 'holistic-monitor.php',
                        type: 'POST',
                        data: 'mode=delete&id='+id,
                        dataType: 'json',
                        success: function(json) {
                            if (json.error) {
                                alert('Error server reported: '+json.error);
                                return;
                            }
                            $('#monitor-overview').DataTable().ajax.reload(reInitTableTools);
                            $('#monitor-details').DataTable().ajax.reload(reInitTableTools);
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
                        }
                    });
                    $(this).dialog("close");
                }
            }
        });     // monitor dialog

    }   // monitorInit()

