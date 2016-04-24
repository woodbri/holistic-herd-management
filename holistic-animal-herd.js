
// reInitTableTools()

function reloadLivestockTab() {
    var hid = herdId;
    $('#herd-table').DataTable().ajax.reload(function(data) {
        unSelectHerdRow();
        if (hid !== null)
            selectHerdRow( hid );
        reInitTableTools();
    });
    if (planData !== null) {
        $("#monthly-sau").DataTable().ajax
            .url("holistic-herd.php?mode=monthly&plan="
            + planId).load(function() {
                $("#total-cell-size").text(number_format(totalCellSize,2));
                $("#peak-monthly-sau").html(peakSau);
                $("#stocking-rate").text(number_format(peakSau ? totalCellSize/peakSau : 0, 4));
                reInitTableTools();
            });
    }
    else {
        $("#monthly-sau").DataTable().ajax
            .url("holistic-herd.php?mode=monthly&plan=")
            .load(reInitTableTools);
        $("#total-cell-size").text('');
        $("#peak-monthly-sau").text('');
        $("#stocking-rate").text('');
    }
}

// TODO: make this loadable via ajax
var animalTypeSelectList = [
    ["Select Animal type from list of enter below",          -1],
    ["Beef cattle, lactating",                              2.25],
    ["Beef cattle, growing and finishing, slaughter stock", 2.3],
    ["Dairy steers",                                        2.3],
    ["Dairy heifers",                                       2.5],
    ["Dairy cows, dry (small or large breed)",              1.8],
    ["Goats, weaned, slaughter or replacement stock",       2.25],
    ["Goats, brood or lactating",                           4.0],
    ["Sheep, weaned, slaughter or replacement stock",       3.3],
    ["Sheep, brood or lactating",                           3.65]
];

function addHerd() {
    if ( planId === null || planId == '')
        alert("First select or create a Plan on the Planning tab.");
    else
        $("#herd-dialog").dialog('open');
}

function loadHerdTableForPlan(plan) {
    $("#herd-table").DataTable().ajax
        .url("./holistic-herd.php?mode=list&plan="+plan)
        .load(function() {
            herdCnt = $("#herd-table").DataTable().data().length;
            unSelectHerdRow();
            reInitTableTools();
        });
}

function addAnimal() {
    if ( herdId === null ) {
        alert("First select a Herd by clicking on (+) in the table above.");
    }
    else {
        $('#animal-id').val('');
        $('#animal-type').val('');
        $('#animal-qty').val('1');
        $('#animal-weight').val('');
        $('#animal-forage').val('');
        $('#animal-tag').val('');
        $("#animal-herd-name").html(herdName);
        $("#animal-herdid").val(herdId);
        $("#animal-dialog").dialog('open');
    }
}

function loadAnimalTableForId(herdid) {
    $('#animal-table').DataTable().ajax
        .url("./holistic-animal.php?mode=list&herdid="+herdid)
        .load(reInitTableTools);
}

function selectHerdRow(herdid) {
    herdid = typeof(herdid) == 'string' ?  herdid = parseInt(herdid) : herdid;
    var htable = $('#herd-table').DataTable();
    var dtRow = htable.rows( function( idx, data, node) {
            return data.id == herdid ?true : false;
    });

    unSelectHerdRow();
    $(dtRow.nodes()).addClass('selected');

    var data = dtRow.data();
    if (typeof data.length === "number")
        data = data[0];
    loadAnimalTableForId(herdid);
    $('#herd-name-span').html(data.name);
    herdId = data.id;
    herdName = data.name;
}

function unSelectHerdRow() {
    var atable = $('#animal-table').DataTable();
    var htable = $('#herd-table').DataTable();
    $('#herd-table tr').removeClass('selected');
    atable.row().remove().draw( false );
    $('#herd-name-span').html('<i>herd-name</i>');
    herdId = null;
    herdName = null;
}


function animalTypeSelectChanged() {
    var item = parseInt($('#animal-type-select').val());
    if (item == 0) return;
    $('#animal-type').val(animalTypeSelectList[item][0]);
    $('#animal-forage').val(animalTypeSelectList[item][1]);
}

function animalSauUpdated() {
    var sau = Math.round(parseFloat($('#animal-sau').val())*100)/100.0;
    var wgt = Math.round(parseFloat($('#animal-weight').val())*100)/100.0;;

    if (sau*1000.0 == wgt) return;
    $('#animal-weight').val(sau*1000.0);
}

function animalWeightUpdated() {
    var sau = Math.round(parseFloat($('#animal-sau').val())*100)/100.0;
    var wgt = Math.round(parseFloat($('#animal-weight').val())*100)/100.0;;

    if (sau*1000.0 == wgt) return;
    $('#animal-sau').val(Math.round(wgt/10)/100.0);
}

function animalHerdInit() {
    $("#herd-table").dataTable({
        dom: 'T<"clear">lfrtip',
        tableTools: {
            sSwfPath: "swf/copy_csv_xls_pdf.swf",
            aButtons: [
                { sExtends: "copy",  sButtonText: "Copy",
                  //mColumns: [2,3,4,5,6] },
                  mColumns: 'visible' },
                { sExtends: "csv",   sButtonText: "CSV/Excel",
                  mColumns: 'visible' },
                { sExtends: "pdf",   sButtonText: "PDF",
                  sTitle: "Herds", sMessage: "Herds",
                  mColumns: 'visible' },
                { sExtends: "print", sButtonText: "Print",
                  sTitle: "Herds", sMessage: "<h1>Herds</h1>",
                  mColumns: 'visible' }
            ]
        },
        stateSave: true,
        deferRender: true,

        //scrollY: "175px",
        scrollCollapse: true,
        paging: false,
        processing: true,

        ajax: "./holistic-herd.php?mode=list&plan="+planId,

        createdRow: function( row, data ) {
            if ( data.id === herdId ) {
                $('td:first', row).trigger( 'click' );
            }
        },

        columnDefs: [
            {
                targets: [ 1 ],
                visible: false,
                searchable: false
            }
        ],
        order: [[ 4, "desc" ]],
        columns: [
            {
                className:  'animal-opener',
                orderable:  false,
                data:       null,
                defaultContent: ''
            },
            { data: "id" },
            { data: "name" },
            { data: "sau", className: "dt-right" },
            { data: "intake", className: "dt-right" },
            { data: "arrival", className: "dt-right" },
            { data: "est_ship", className: "dt-right" },
        ],
        preDrawCallback: function() {
            $("#herd-table tbody").off('click', 'tr');
            $("#herd-table tbody").off('click', 'td.animal-opener');
        },
        drawCallback: function() {
            $("#herd-table tbody").on('click', 'tr', function() {
                var data = $('#herd-table').DataTable().row(this).data();
                $('#herd-id').val(data.id);
                $('#herd-name').val(data.name);
                $('#herd-sau').val(data.sau);
                $('#herd-intake').val(data.intake);
                $('#herd-arrival').val(data.arrival);
                $('#herd-est-ship').val(data.est_ship);
                $('#herd-dialog').dialog('open');
            });

            $("#herd-table tbody").on('click', 'td.animal-opener', function(ev) {
                ev.stopPropagation();
                var atable = $('#animal-table').DataTable();
                var htable = $('#herd-table').DataTable();
                var tr = $(this).closest('tr');
                if ( $(tr).hasClass('selected') ) {
                    $(tr).removeClass('selected');
                    atable.row().remove().draw( false );
                    $('#herd-name-span').html('<i>herd-name</i>');
                    herdId = null;
                    herdName = null;
                }
                else {
                    htable.$('tr.selected').removeClass('selected');
                    atable.row().remove().draw( false );
                    $(tr).addClass('selected');
                    var data = htable.row(tr).data();
                    loadAnimalTableForId(data.id);
                    $('#herd-name-span').html(data.name);
                    herdId = data.id;
                    herdName = data.name;
                }
            });
        }
    });

    $("#herd-dialog").dialog({
        autoOpen: false,
        modal: true,
        closeOnEscape: true,
        close: function( event, ui ) {
            $('#herd-id').val('');
            $('#herd-name').val('');
            $('#herd-sau').val('');
            $('#herd-intake').val('');
            $('#herd-arrival').val('');
            $('#herd-est-ship').val('');
        },
        buttons: {
            "Cancel": function() {
                $(this).dialog("close");
        },
            "Save Changes": function() {
                var data;
                var id        = $('#herd-id').val();
                var name      = $('#herd-name').val();
                if (planId === null) {
                    alert("First select or create a Plan on the Planning tab.");
                    return;
                }

                if (id === "")
                    data = 'mode=add&plan='+planId+'&name='+name;
                else
                    data = 'mode=update&id='+id+'&name='+name;

                $.ajax({
                    url: 'holistic-herd.php',
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(json) {
                        if (json.error) {
                            alert('Error server reported:'+json.error);
                            return;
                        }
                        if (json.cal) {
                            $('#calendar').fullCalendar( 'removeEvents', json.cal.id );
                            $('#calendar').fullCalendar( 'renderEvent', json.cal, false);
                        }
                        $('#herd-table').DataTable().ajax.reload();
                        //$("#calendar").fullCalendar('refetchEvents');
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert( "ERROR:\n" + textStatus +
                               "\n" + errorThrown);
                    }
                });
                $(this).dialog("close");
            },
            "Delete Herd": function() {
                var id = $('#herd-id').val();
                $.ajax({
                    url: 'holistic-herd.php',
                    data: 'mode=delete&id=' + id,
                    type: 'POST',
                    dataType: 'json',
                    success: function(json) {
                        if (json.error) {
                            alert('Error server reported:'+json.error);
                            return;
                        }
                        if (json.ids) {
                            for (var i=0; i<ids.length; i++)
                                $('#calendar').fullCalendar( 'removeEvents', ids[i] );
                        }
                        // reload the herd table to get it to update
                        // this will also unselect a selected row
                        $('#herd-table').DataTable().ajax.reload(
                            function () {
                                // so clear the Animal table and its title
                                $('#animal-table').DataTable().row().remove().draw( false );
                                $('#herd-name-span').html('<i>herd-name</i>');
                                // and update the calendar
                                //$("#calendar").fullCalendar('refetchEvents');
                            }
                        );
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert( "ERROR:\n" + textStatus +
                               "\n" + errorThrown);
                    }
                });
                $(this).dialog("close");
            }
        }
    });     // herd dialog

    // *******************************************************

    $("#animal-table").dataTable({
        dom: 'T<"clear">lfrtip',
        tableTools: {
            sSwfPath: "swf/copy_csv_xls_pdf.swf",
            aButtons: [
                { sExtends: "copy",  sButtonText: "Copy",
                  //mColumns: [0,1,2,3,4,5,6,7,8] },
                  mColumns: 'visible' },
                { sExtends: "csv",   sButtonText: "CSV/Excel",
                  mColumns: 'visible' },
                { sExtends: "pdf",   sButtonText: "PDF",
                  sPdfOrientation: "landscape",
                  sTitle: "Animals", sMessage: "Animals in Herd",
                  mColumns: 'visible' },
                { sExtends: "print", sButtonText: "Print",
                  sTitle: "Animals", sMessage: "<h1>Animals in Herd</h1>",
                  mColumns: 'visible' }
            ]
        },
        stateSave: true,
        deferRender: true,

        //scrollY: "200px",
        scrollCollapse: true,
        paging: false,
        processing: true,

        ajax: "./holistic-animal.php?mode=list",
        columnDefs: [
            {
                targets: "hidden",
                visible: false,
                searchable: false
            },
        ],
        order: [[ 0, "desc" ]],
        columns: [
            { data: "type", className: "dt-left" },
            { data: "qty", className: "dt-right" },
            { data: "sau", className: "dt-right" },
            { data: "weight", className: "dt-right" },
            { data: "forage", className: "dt-right" },
            { data: "tag", className: "dt-right" },
            { data: "arrival", className: "dt-right" },
            { data: "est_ship", className: "dt-right" },
            { data: "act_ship", className: "dt-right" },
            { data: "notes", visible: false },
            { data: "herdid", visible: false },
            { data: "id", visible: false }
        ],
        preDrawCallback: function() {
            $("#animal-table tbody").off('click', 'tr');
        },
        drawCallback: function() {
            $("#animal-table tbody").on('click', 'tr', function() {
                var data = $('#animal-table').DataTable().row(this).data();
                $('#animal-id').val(data.id);
                $('#animal-type').val(data.type);
                $('#animal-qty').val(data.qty);
                $('#animal-sau').val(data.sau);
                $('#animal-weight').val(data.weight);
                $('#animal-forage').val(data.forage);
                $('#animal-tag').val(data.tag);
                $('#animal-arrival').val(data.arrival);
                $('#animal-est-ship').val(data.est_ship);
                $('#animal-act-ship').val(data.act_ship);
                $('#animal-notes').val(data.notes);
                $('#animal-herdid').val(data.herdid);
                $('#animal-herd-name').html(herdName);
                $('#animal-dialog').dialog('open');
            });
        }
    });

    $("#animal-dialog").dialog({
        autoOpen: false,
        minWidth: 340,
        modal: true,
        closeOnEscape: true,
        close: function( event, ui ) {
            $('#animal-id').val('');
            $('#animal-type').val('');
            $('#animal-qty').val('1');
            $('#animal-sau').val('');
            $('#animal-weight').val('');
            $('#animal-forage').val('');
            $('#animal-tag').val('');
            $('#animal-arrival').val(planData.start_date);
            $('#animal-est-ship').val(planData.end_date);
            $('#animal-act-ship').val('');
            $('#animal-notes').val('');
            $('#animal-herdid').val('');
            $('#animal-type-select').val(0);
        },
        buttons: {
            "Cancel": function() {
                $(this).dialog("close");
            },
            "Save Changes": function() {
                var data;
                var id        = $('#animal-id').val();
                var type      = $('#animal-type').val();
                var qty       = $('#animal-qty').val();
                var sau       = $('#animal-sau').val();
                var weight    = $('#animal-weight').val();
                var forage    = $('#animal-forage').val();
                var tag       = $('#animal-tag').val();
                var arrival   = $('#animal-arrival').val();
                var est_ship  = $('#animal-est-ship').val();
                var act_ship  = $('#animal-act-ship').val();
                var notes     = $('#animal-notes').val();
                var herdid    = herdId;
                if (id === "")
                    data = 'mode=add&type='+type+'&qty='+qty+'&sau='+sau+'&weight='+weight+'&forage='+forage+'&tag='+tag+'&herdid='+herdid+'&arrival='+arrival+'&est_ship='+est_ship+'&act_ship='+act_ship+'&notes='+notes;
                else
                    data = 'mode=update&id='+id+'&type='+type+'&qty='+qty+'&sau='+sau+'&weight='+weight+'&forage='+forage+'&tag='+tag+'&herdid='+herdid+'&arrival='+arrival+'&est_ship='+est_ship+'&act_ship='+act_ship+'&notes='+notes;

                $.ajax({
                    url: 'holistic-animal.php',
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(json) {
                        if (json.error) {
                            alert('Error server reported:'+json.error);
                            return;
                        }
                        $('#herd-table').DataTable().ajax.reload(
                            function() {
                                selectHerdRow(herdid);
                            });
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert( "ERROR:\n" + textStatus +
                               "\n" + errorThrown);
                    }
                });
                $(this).dialog("close");
            },
            "Delete Animal": function() {
                var id = $('#animal-id').val();
                var herdid    = $('#animal-herdid').val();
                $.ajax({
                    url: 'holistic-animal.php',
                    data: 'mode=delete&id=' + id,
                    type: 'POST',
                    dataType: 'json',
                    success: function(json) {
                        if (json.error) {
                            alert('Error server reported:'+json.error);
                            return;
                        }
                        // so clear the Animal table and its title
                        $('#animal-table').DataTable().row().remove().draw( false );
                        $('#herd-name-span').html('<i>herd-name</i>');

                        // the caldendar will refresh when its tab is activated

                        // reload the herd table to get it to update
                        $('#herd-table').DataTable().ajax
                            .reload( function() {
                                selectHerdRow(herdid);
                            });
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert( "ERROR:\n" + textStatus +
                               "\n" + errorThrown);
                    }
                });
                $(this).dialog("close");
            }
        }
    });     // animal dialog
        
    $("#monthly-sau").dataTable({
        dom: 'T<"clear">lfrtip',
        tableTools: {
            sSwfPath: "swf/copy_csv_xls_pdf.swf",
            aButtons: [
                { sExtends: "copy",  sButtonText: "Copy",
                  //mColumns: [0,1,2,3,4,5,6,7,8,9,10,11,12] }
                  mColumns: 'visible' },
                { sExtends: "csv",   sButtonText: "CSV/Excel",
                  mColumns: 'visible' },
                { sExtends: "pdf",   sButtonText: "PDF",
                  sPdfOrientation: "landscape",
                  sTitle: "SAU-by-Month", sMessage: "SAU by Month",
                  mColumns: 'visible' },
                { sExtends: "print", sButtonText: "Print",
                  sTitle: "SAU-by-Month", sMessage: "<h1>SAU by Month</h1>",
                  mColumns: 'visible' }
            ]
        },
        stateSave: true,
        deferRender: true,

        //scrollY: "175px",
        scrollCollapse: true,
        paging: false,
        processing: true,

        ajax: "holistic-herd.php?mode=monthly&plan=",

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
            { data: "herdid" }
        ],

        "footerCallback": function(row, data, start, end, display) {
            var api = this.api(), data;

            var intVal = function( i ) {
                var val = typeof i === 'string' ?  i*1 :
                    typeof i === 'number' ? i : 0;
                return val;
            };

            peakSau = 0.0;

            for (var i=0; i<12; i++) {
                total = api
                    .column(i+1)
                    .data()
                    .reduce( function(a, b) {
                        return a + intVal(b);
                        }, 0);

                // update footer
                $( api.column( i+1 ).footer() ).html( total );
                if (total > peakSau) peakSau = total;
            }
        }
    }); // monthly-sau dataTable


    var animalTypeSelect = $('#animal-type-select');
    $.each(animalTypeSelectList, function(i, el) {
        animalTypeSelect.append("<option value=" + i + ">" + el[0] + "</option>");
    });

}
