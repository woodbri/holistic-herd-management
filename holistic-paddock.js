function selectAllPaddocks() {
    var table = $("#paddock-list").DataTable();
        if (paddockSelect) {
            table.rows().eq(0).each(function(el, idx) {
                var cell = table.cell( idx, 2 );
                if ( intVal(cell.data()) == 0 )
                    cell.data( 1 ).draw();
            });
            table.rows().nodes().to$().addClass( 'selected' );
        }
        else {
            table.rows().eq(0).each(function(el, idx) {
                var cell = table.cell( idx, 2 );
                if ( intVal(cell.data()) > 0 )
                    cell.data( 0 ).draw();
            });
            table.rows().nodes().to$().removeClass( 'selected' );
        }

        paddockSelect = ! paddockSelect;
}

function setPaddocksForPlan() {
    var selected = $("#paddock-list").DataTable()
        .rows('.selected').data();
    if (selected.length == 0) {
        alert("Please select some paddocks by clicking on them in the scrolling list!");
        return false;
    }

    var ids = [];
    for (var i=0; i<selected.length; i++)
        ids.push(selected[i].id + '@' + selected[i].cnt);
    var data = 'mode=ppset&plan='+planId+'&ids='+ids.join(',');
    $.ajax({
        url: "holistic-paddock.php",
        type: 'POST',
        "data": data,
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                alert('Error server reported:'+json.error);
                return;
            }
            reloadPaddockList(reInitTableTools);
        },
        error: function(jqXHR, textStatus, errorThrown) {
            alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
        }
     });    // $.ajax
}

function reloadPaddockTab() {
    $('#paddock-table').DataTable().ajax.reload(reInitTableTools);
}

function reloadPaddockList(callback) {
    if (planId !== null) {
        $('#paddock-list').DataTable().ajax
            .url("holistic-paddock.php?mode=list3&plan="
            +planId)
            .load(callback);
    }
}

function reloadPaddockAvailable() {
    if (planId !== null) {
        $("#paddock-available").DataTable().ajax
            .url("holistic-paddock.php?mode=avail&plan="
            + planId).load(reInitTableTools);
    }
    else {
        $("#paddock-available").DataTable().ajax
            .url("holistic-paddock.php?mode=avail&plan=")
            .load(reInitTableTools);
    }
}

function addPaddock() {
    $('#paddock-dialog').dialog('open');
}

function populatePaddockSelect() {
    var selected = $("#paddock-list").DataTable()
        .rows('.selected').data();
    if (selected.length == 0) return false;

    var sel = $("#exc-pad-select-list");
    $(sel).find('option').remove().end()
        .append('<option value="" selected>Select Paddock</option>');
    $.each(selected, function(i,item) {
        $(sel).append(new Option(item.name, item.id));
    });
}

function addExclusion() {
    $('#exc-pad-select-list').val('');
    populatePaddockSelect();
    $('#exc-type option:first').prop('selected', true);
    $('#exc-start').val('');
    $('#exc-end').val('');
    $('#exc-reason').val('');
    $('#exc-padid').val('');
    $('#exclusion-id').val('');
    $('#paddock-exc-dialog').dialog('open');
}

function savePaddockProductivity() {
    if (planId === null) return false;

    var table = $("#paddock-productivity").DataTable();
    var data = table.$('input').serialize();
    data = 'mode=ppupdate&plan='+planId+'&'+data;
    $.ajax({
        url: "holistic-paddock.php",
        type: 'POST',
        "data": data,
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                alert('Error server reported:'+json.error);
                return;
            }
            $("#paddock-productivity").DataTable()
                .ajax
                .url("holistic-paddock.php?mode=pplist&plan="+planId)
                .load(reInitTableTools);
        },
        error: function(jqXHR, textStatus, errorThrown) {
            alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
        }
     });    // $.ajax
    return false;
}

function saveRecoveryPeriods() {
    if (planId === null) return false;

    var table = $("#recovery-by-month").DataTable();
    var data = table.$('input').serialize();
    var data = 'mode=prupdate&plan='+planId+'&'+data;
    $.ajax({
        url: "holistic-paddock.php",
        type: 'POST',
        "data": data,
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                alert('Error server reported:'+json.error);
                return;
            }
            $("#recovery-by-month").DataTable()
                .ajax
                .url("holistic-paddock.php?mode=prlist&plan="+planId)
                .load(reInitTableTools);
        },
        error: function(jqXHR, textStatus, errorThrown) {
            alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
        }
     });    // $.ajax
    return false;
}

function updateGrazingPatterns() {
    var which = $("#grazing-which-pad").val();
    var year  = $("#grazing-which-yrs").val();
    var data  = 'mode=gpattern&years='+year;
    if (which == '1')
        data += '&plan='+planId;
    $.ajax({
        url: "holistic-paddock.php",
        type: 'POST',
        "data": data,
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                alert('Error server reported:'+json.error);
                return;
            }
            $("#grazing-pattern").DataTable()
                .ajax
                .url("holistic-paddock.php?"+data)
                .load(reInitTableTools);
        },
        error: function(jqXHR, textStatus, errorThrown) {
            alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
        }
    });
    return false;
}

function paddockInit() {

        $("#paddock-table").DataTable({
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
                      sTitle: "Paddock-Definition", sMessage: "Paddock Definition" },
                    { sExtends: "print", sButtonText: "Print",
                      mColumns: 'visible' ,
                      sTitle: "Paddock-Definition", sMessage: "<h1>Paddock Definition</h1>" },
                ]
            },
            stateSave: true,
            deferRender: true,

            //scrollY: "175px",
            scrollCollapse: true,
            paging: false,
            processing: true,

            ajax: "./holistic-paddock.php?mode=list",

            createdRow: function( row, data ) {
                if ( data.id === paddockId ) {
                    $('td:first', row).trigger( 'click' );
                }
            },

            columnDefs: [
                {
                    targets: [ 0 ],
                    visible: false,
                    searchable: false
                },
                {
                    targets: [ -1 ],
                    visible: false
                }
            ],
            order: [[ 1, "asc" ]],
            columns: [
                { data: "id" },
                { data: "name" },
                { data: "area", className: "dt-right" },
                { data: "atype", className: "dt-right" },
                { data: "crop" },
                { data: "description" }
            ],
            preDrawCallback: function() {
                 $("#paddock-table tbody").off('click', 'tr');
            },
            drawCallback: function() {
                $("#paddock-table tbody").on('click', 'tr', function() {
                    var data = $('#paddock-table').DataTable().row(this).data();
                    $('#paddock-id').val(data.id);
                    $('#paddock-name').val(data.name);
                    $('#paddock-area').val(data.area);
                    $('#paddock-atype').val(data.atype);
                    $('#paddock-area').prop( 'disabled', data.atype == 'geometry' );
                    $('#paddock-atype').prop( 'disabled', data.atype == 'geometry' );
                    $('#paddock-description').val(data.description);
                    $('#paddock-dialog').dialog('open');
                });
            }
        });

        $("#paddock-dialog").dialog({
            autoOpen: false,
            modal: true,
            minWidth: 315,
            closeOnEscape: true,
            close: function( event, ui ) {
                $('#paddock-id').val('');
                $('#paddock-name').val('');
                $('#paddock-area').val('');
                $('#paddock-atype').val('');
                $('#paddock-crop').val('');
                $('#paddock-description').val('');
            },
            buttons: {
                "Cancel": function() {
                    $(this).dialog("close");
                },
                "Save Changes": function() {
                    var data;
                    var id          = $('#paddock-id').val();
                    var name        = $('#paddock-name').val();
                    var area        = $('#paddock-area').val();
                    var atype       = $('#paddock-atype').val();
                    var crop        = $('#paddock-crop').val();
                    var desc        = $('#paddock-description').val();
                    if (id === "")
                        data = 'mode=add&name='+name+'&area='+area+'&atype='+atype+'&crop='+crop+'&description='+desc;
                    else
                        data = 'mode=update&id='+id+'&name='+name+'&area='+area+'&atype='+atype+'&crop='+crop+'&description='+desc;

                    $.ajax({
                        url: 'holistic-paddock.php',
                        type: 'POST',
                        data: data,
                        dataType: 'json',
                        success: function(json) {
                            if (json.error) {
                                alert('Error server reported:'+json.error);
                                return;
                            }
                            $('#paddock-table').DataTable().ajax.reload(reInitTableTools);
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            alert( "ERROR:\n" + textStatus +
                                   "\n" + errorThrown);
                        }
                    });
                    $(this).dialog("close");
                },
                "Delete Paddock": function() {
                    var id = $('#paddock-id').val();
                    $.ajax({
                        url: 'holistic-paddock.php',
                        data: 'mode=delete&id=' + id,
                        type: 'POST',
                        dataType: 'json',
                        success: function(json) {
                            if (json.error) {
                                alert('Error server reported:'+json.error);
                                return;
                            }
                            $('#paddock-table').DataTable().ajax.reload(reInitTableTools);
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            alert( "ERROR:\n" + textStatus +
                                   "\n" + errorThrown);
                        }
                    });
                    $(this).dialog("close");
                }
            }
        });     // paddock dialog

    $("#paddock-list").DataTable({
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
                  sTitle: "PaddockInPlan", sMessage: "Paddocks in Plan" },
                { sExtends: "print", sButtonText: "Print",
                  mColumns: 'visible' ,
                  sTitle: "PaddockInPlan", sMessage: "<h1>Paddocks in Plan</h1>" }
            ]
        },
        stateSave: true,
        deferRender: true,

        //scrollY: "175px",
        //scrollCollapse: true,

        paging: false,
        //pagingType: "full_numbers",
        //lengthChange: true,
        //lengthMenu: [[10,25,50,-1],[10,25,50,"All"]],

        processing: true,

        //ajax: "holistic-paddock.php?mode=list3",

        createdRow: function( row, data ){
            if (data.cnt > 0) {
                $(row).addClass('selected');
            }
        },

        columnDefs: [
            {
                targets: [ -1, -2 ],
                visible: false,
                searchable: false
            }
        ],
        order: [[ 3, "asc" ]],
        columns: [
            {
                className:  'paddock-inc',
                orderable:  false,
                data:       null,
                defaultContent: ''
            },
            {
                className:  'paddock-dec',
                orderable:  false,
                data:       null,
                defaultContent: ''
            },
            { data: "cnt", className: "dt-right" },
            { data: "name" },
            { data: "area", className: "dt-right" },
            { data: "planname", className: "dt-right" },
            { data: "planid" },
            { data: "id" }
        ],

        preDrawCallback: function() {
            $("#paddock-list tbody").off('click', 'td.paddock-inc');
            $("#paddock-list tbody").off('click', 'td.paddock-dec');
        },
        drawCallback: function() {
            $("#paddock-list tbody").on('click', 'td.paddock-inc', function(ev) {
                ev.stopPropagation();
                var table = $("#paddock-list").DataTable();
                var tr = $(this).closest('tr');
                var cell = table.cell( tr, 2 );
                // currently limit paddock inclusion to one
                if (intVal(cell.data()) < 1)
                    cell.data( intVal(cell.data()) + 1 ).draw();
                if ( intVal(cell.data()) > 0 )
                    $(tr).addClass('selected');
            });
            $("#paddock-list tbody").on('click', 'td.paddock-dec', function(ev) {
                ev.stopPropagation();
                var table = $("#paddock-list").DataTable();
                var tr = $(this).closest('tr');
                var cell = table.cell( tr, 2 );
                if ( intVal(cell.data()) > 0 )
                    cell.data( intVal(cell.data()) - 1 ).draw();
                if ( intVal(cell.data()) == 0 )
                    $(tr).removeClass('selected');
            });
        },
        footerCallback: function() {
            var api = this.api();
            totalCellSize = api
                .column(4)
                .data()
                .reduce( function(a, b) {
                    return a + intVal(b);
                    }, 0);
        }
    }); // paddock-list dataTable


    $("#paddock-exc-dialog").dialog({
        autoOpen: false,
        modal: true,
        minWidth: 335,
        closeOnEscape: true,
        close: function( event, ui ) {
            $('#exclusion-id').val('');
            $('#exc-pad-select-list').val('');
            $('#exc-type option:first').prop('selected', true);
            $('#exc-start').val('');
            $('#exc-end').val('');
            $('#exc-reason').val('');
        },
        buttons: {
            "Cancel": function() {
                $(this).dialog("close");
        },
            "Save Changes": function() {
                var data;
                var id          = $('#exclusion-id').val();
                var padid       = $('#exc-pad-select-list').val();
                var exc_type    = $('#exc-type').val();
                var exc_start   = $('#exc-start').val();
                var exc_end     = $('#exc-end').val();
                var reason      = $('#exc-reason').val();
                if (id === "")
                    data = 'mode=add&plan='+planId+'&padid='+padid+'&exc_type='+exc_type+'&exc_start='+exc_start+'&exc_end='+exc_end+'&reason='+reason;
                else
                    data = 'mode=update&id='+id+'&plan='+planId+'&padid='+padid+'&exc_type='+exc_type+'&exc_start='+exc_start+'&exc_end='+exc_end+'&reason='+reason;

                $.ajax({
                    url: 'holistic-paddock-exc.php',
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(json) {
                        if (json.error) {
                            alert('Error server reported:'+json.error);
                            return;
                        }
                        $('#exclusion-table').DataTable().ajax.reload(reInitTableTools);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert( "ERROR:\n" + textStatus +
                               "\n" + errorThrown);
                    }
                });
                $(this).dialog("close");
            },
            "Delete Exclusion": function() {
                var id = $('#exclusion-id').val();
                $.ajax({
                    url: 'holistic-paddock-exc.php',
                    data: 'mode=delete&id=' + id,
                    type: 'POST',
                    dataType: 'json',
                    success: function(json) {
                        if (json.error) {
                            alert('Error server reported:'+json.error);
                            return;
                        }
                        // reload the paddock table to get it to update
                        // this will also unselect a selected row
                        $('#exclusion-table').DataTable().ajax.reload(reInitTableTools);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert( "ERROR:\n" + textStatus +
                               "\n" + errorThrown);
                    }
                });
                $(this).dialog("close");
            }
        }
    });     // paddock dialog

    $("#exclusion-table").DataTable({
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
                  sTitle: "PaddockExclusions", sMessage: "Paddock Exclusions" },
                { sExtends: "print", sButtonText: "Print",
                  mColumns: 'visible' ,
                  sTitle: "PaddockExclusions", sMessage: "<h1>Paddock Exclusions</h1>" }
            ]
        },
        stateSave: true,
        deferRender: true,

        //scrollY: "175px",
        scrollCollapse: true,
        paging: false,
        processing: true,

        ajax: "holistic-paddock-exc.php?mode=list",

        columnDefs: [
            {
                targets: [ -1, -2, -3 ],
                visible: false
            }
        ],
        order: [[ 0, "asc" ]],
        columns: [
            { data: "name" },
            { data: "exc_type" },
            { data: "exc_start", className: "dt-right" },
            { data: "exc_end", className: "dt-right" },
            { data: "reason" },
            { data: "plan" },
            { data: "id" },
            { data: "padid" }
        ],

        preDrawCallback: function() {
            $("#exclusion-table tbody").off('click', 'tr');
        },
        drawCallback: function() {
            $("#exclusion-table tbody").on('click', 'tr', function() {
                var data = $('#exclusion-table').DataTable().row(this).data();
                $('#exc-pad-select-list').val(data.padid);
                populatePaddockSelect();
                $('#exc-start').val(data.exc_start);
                $('#exc-end').val(data.exc_end);
                $('#exc-reason').val(data.reason);
                $('#exc-padid').val(data.padid);
                $('#exclusion-id').val(data.id);
                $('#paddock-exc-dialog').dialog('open');
            });
        }
    }); // paddock-list dataTable


    $("#paddock-available").DataTable({
        dom: 'T<"clear">lfrtip',
        tableTools: {
            sSwfPath: "swf/copy_csv_xls_pdf.swf",
            aButtons: [
                { sExtends: "copy",  sButtonText: "Copy",
                  mColumns: 'visible' },
                { sExtends: "csv",   sButtonText: "CSV/Excel",
                  mColumns: 'visible' },
                { sExtends: "pdf",   sButtonText: "PDF",
                  "sPdfOrientation": "landscape",
                  mColumns: 'visible' ,
                  sTitle: "PaddockAvailabilityByMonth", sMessage: "Paddock Availability by Month" },
                { sExtends: "print", sButtonText: "Print",
                  mColumns: 'visible' ,
                  sTitle: "PaddockAvailabilityByMonth", sMessage: "<h1>Paddock Availability by Month</h1>" }
            ]
        },
        stateSave: true,
        deferRender: true,

        //scrollY: "175px",
        scrollCollapse: true,
        paging: false,
        processing: true,

        ajax: "holistic-paddock.php?mode=avail&year=",

        columnDefs: [
            {
                targets: [ -1 ],
                visible: false
            }
        ],
        order: [[ 0, "asc" ]],
        columns: [
            { data: "name" },
            { data: "jan", className: "dt-right" },
            { data: "feb", className: "dt-right" },
            { data: "mar", className: "dt-right" },
            { data: "apr", className: "dt-right" },
            { data: "may", className: "dt-right" },
            { data: "jun", className: "dt-right" },
            { data: "jul", className: "dt-right" },
            { data: "aug", className: "dt-right" },
            { data: "sep", className: "dt-right" },
            { data: "oct", className: "dt-right" },
            { data: "nov", className: "dt-right" },
            { data: "dec", className: "dt-right" },
//            { data: "productivity", className: "dt-right" },
//            { data: "forage", className: "dt-right" },
            { data: "padid" }
        ],

        "footerCallback": function(row, data, start, end, display) {
            var api = this.api(), data;
            for (var i=0; i<12; i++) {
                total = api
                    .column(i+1)
                    .data()
                    .reduce( function(a, b) {
                        return a + intVal(b);
                        }, 0);

                // update footer
                $( api.column( i+1 ).footer() ).html( total );
            }
        }
    }); // paddock-available dataTable


    $("#paddock-productivity").DataTable({
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
                  sTitle: "PaddockRatedProductivity", sMessage: "Paddock Rated Productivity" },
                { sExtends: "print", sButtonText: "Print",
                  mColumns: 'visible' ,
                  sTitle: "PaddockRatedProductivity", sMessage: "<h1>Paddock Rated Productivity</h1>" }
            ]
        },
        stateSave: true,
        deferRender: true,

        //scrollY: "175px",
        scrollCollapse: true,
        paging: false,
        processing: true,

        //ajax: "holistic-paddock.php?mode=pplist&plan=",

        columnDefs: [
            {
                targets: [ -1, -2 ],
                visible: false
            }
        ],
        order: [[ 0, "asc" ]],
        columns: [
            { data: "name" },
            { data: "qual", className: "dt-right" },
            { data: "days", className: "dt-right" },
            { data: "padid" },
            { data: "id" }
        ],

        "rowCallback": function( row, data ) {
            $('td:eq(1)', row).html(
                '<input id="pqr_'+data.id+'" name="pqr_'+data.id+'" value="'+data.qual+'">'
            );
        },

        "footerCallback": function(row, data, start, end, display) {
            var api = this.api();
            var totForage = api
                .column(1)
                .data()
                .reduce( function(a, b) {
                    return a + intVal(b);
                    }, 0);

            var totADA = api
                .column(2)
                .data()
                .reduce( function(a, b) {
                    return a + intVal(b);
                    }, 0);

            // update footer
            $("#average-forage-qual").html(
                Math.round(totForage / api.data().length * 10) / 10
            );
            $("#average-animal-days").html(
                Math.round(totADA / api.data().length * 10) / 10
            );
        }
    }); // paddock-productivity dataTable


    $("#recovery-by-month").DataTable({
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
                  sTitle: "PaddockRecoveryByMonth", sMessage: "Paddock Recovery by Month" },
                { sExtends: "print", sButtonText: "Print",
                  mColumns: 'visible' ,
                  sTitle: "PaddockRecoveryByMonth", sMessage: "<h1>Paddock Recovery by Month</h1>" }
            ]
        },
        stateSave: true,
        deferRender: true,

        //scrollY: "175px",
        scrollCollapse: true,
        paging: false,
        processing: true,

        ajax: "holistic-paddock.php?mode=prlist&plan=",

        columnDefs: [
            {
                targets: [ -1 ],
                visible: false
            }
        ],
        ordering: false,
        columns: [
            { data: "mname" },
            { data: "minrecov", className: "dt-right" },
            { data: "maxrecov", className: "dt-right" },
            { data: "month" }
        ],

        "rowCallback": function( row, data ) {
            var minrecov = data.minrecov === null ? '' : data.minrecov;
            var maxrecov = data.maxrecov === null ? '' : data.maxrecov;
            $('td:eq(1)', row).html(
                '<input id="rmin_'+data.month+'" name="rmin_'+data.month+'" value="'+minrecov+'">'
            );
            $('td:eq(2)', row).html(
                '<input id="rmax_'+data.month+'" name="rmax_'+data.month+'" value="'+maxrecov+'">'
            );
        },

        "footerCallback": function(row, data, start, end, display) {
            var api = this.api();
            var minmin =  99999999.9;
            var maxmax = -99999999.9;

            var mintotal = api
                .column(1)
                .data()
                .reduce( function(a, b) {
                    b = intVal(b);
                    minmin = Math.min(minmin, b);
                    return a + b;
                    }, 0);

            var maxtotal = api
                .column(2)
                .data()
                .reduce( function(a, b) {
                    b = intVal(b);
                    maxmax = Math.max(maxmax, b);
                    return a + b;
                    }, 0);

            // update footer
            reloadPaddockList( function() {
                var paddockCnt = $("#paddock-list tr.selected").length;
                $("#est-min-grazing-period").html(
                    Math.round(minmin / (paddockCnt - herdCnt) * 10) / 10
                );
                $("#est-max-grazing-period").html(
                    Math.round(maxmax / (paddockCnt - herdCnt) * 10) / 10
                );
                reInitTableTools();
            });
        }
    }); // recovery-by-month dataTable


    $("#actual-min-max-grazing").DataTable({
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
                  sTitle: "PaddockMinMaxGrazingPeriod", sMessage: "Paddock Actual Min/Max Grazing Periods" },
                { sExtends: "print", sButtonText: "Print",
                  mColumns: 'visible' ,
                  sTitle: "PaddockMinMaxGrazingPeriod", sMessage: "<h1>Paddock Actual Min/Max Grazing Periods</h1>" }
            ]
        },
        stateSave: true,
        deferRender: true,

        //scrollY: "175px",
        scrollCollapse: true,
        paging: false,
        processing: true,

        //ajax: "holistic-paddock.php?mode=listgraz&plan=",

        columnDefs: [
            {
                targets: [ -1 ],
                visible: false
            }
        ],
        ordering: false,
        columns: [
            { data: "name" },   // paddock name
            { data: "area", className: "dt-right" },   // paddock size
            { data: "qual", className: "dt-right" },   // paddock quality
            { data: "actmingd", className: "dt-right" },
            { data: "actmaxgd", className: "dt-right" },
            { data: "padid" }
        ],

        "footerCallback": function(row, data, start, end, display) {
            var api = this.api();
            var maxmin  = 0;
            var maxmax  = 0;

            var totarea = api
                .column(1)
                .data()
                .reduce( function(a, b) {
                    var b = intVal(b);
                    return a + b;
                    }, 0);

            var totada = api
                .column(2)
                .data()
                .reduce( function(a, b) {
                    var b = intVal(b);
                    return a + b;
                    }, 0);

            var mintotal = api
                .column(3)
                .data()
                .reduce( function(a, b) {
                    var b = intVal(b);
                    if (maxmin < b) maxmin = b;
                    return a + b;
                    }, 0);

            var maxtotal = api
                .column(4)
                .data()
                .reduce( function(a, b) {
                    var b = intVal(b);
                    if (maxmax < b) maxmax = b;
                    return a + b;
                    }, 0);

            // update footer
            $("#chk-min-recovery").html(
                Math.round((mintotal - maxmin) * 10) / 10.0
            );
            $("#chk-max-recovery").html(
                Math.round((maxtotal - maxmax) * 10) / 10.0
            );
            $( api.column( 1 ).footer() ).html(
                Math.round(totarea * 10) / 10.0
            );
            $( api.column( 2 ).footer() ).html(
                Math.round(totada * 10) / 10.0
            );
            $( api.column( 3 ).footer() ).html(mintotal);
            $( api.column( 4 ).footer() ).html(maxtotal);
        }
    }); // actual-min-max-grazing dataTable

    $("#grazing-pattern").DataTable({
        dom: 'T<"clear">lfrtip',
        tableTools: {
            sSwfPath: "swf/copy_csv_xls_pdf.swf",
            aButtons: [
                { sExtends: "copy",  sButtonText: "Copy",
                  mColumns: 'visible' },
                { sExtends: "csv",   sButtonText: "CSV/Excel",
                  mColumns: 'visible' },
                { sExtends: "pdf",   sButtonText: "PDF",
                  sPdfOrientation: "landscape",
                  mColumns: 'visible' ,
                  sTitle: "PaddockHistoricalGrazingPatterns", sMessage: "Paddock Historical Grazing Patterns" },
                { sExtends: "print", sButtonText: "Print",
                  mColumns: 'visible' ,
                  sTitle: "PaddockHistoricalGrazingPatterns", sMessage: "<h1>Paddock Historical Grazing Patterns</h1>" }
            ]
        },
        stateSave: true,
        deferRender: true,

        //scrollY: "350px",
        scrollCollapse: true,
        paging: false,
        processing: true,

        //ajax: "holistic-paddock.php?mode=gpattern",

        columnDefs: [
            {
                targets: [ -1 ],
                visible: false
            }
        ],
        order: [[ 0, "asc" ]],
        columns: [
            { data: "name" },
            { data: "jan", className: "dt-center" },
            { data: "feb", className: "dt-center" },
            { data: "mar", className: "dt-center" },
            { data: "apr", className: "dt-center" },
            { data: "may", className: "dt-center" },
            { data: "jun", className: "dt-center" },
            { data: "jul", className: "dt-center" },
            { data: "aug", className: "dt-center" },
            { data: "sep", className: "dt-center" },
            { data: "oct", className: "dt-center" },
            { data: "nov", className: "dt-center" },
            { data: "dec", className: "dt-center" },
            { data: "total", className: "dt-center" },
            { data: "padid" }
        ]
    }); // grazing-pattern dataTable




}   // paddockInit()

