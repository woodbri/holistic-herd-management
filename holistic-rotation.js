
    function setDefaultGrazingDays() {
        var defgd = intVal($("#plot-default-gd").val());
        // if there is no plan or the value has not changed return
        if (!planData || planData.defgd == defgd) return false;

        var data = 'mode=setdefgd&plan='+planId+'&defgd='+defgd;
        $.ajax({
            url: 'holistic-plan.php',
            type: 'post',
            data: data,
            datatype: 'json',
            success: function(json) {
                if (json.error) {
                    alert('error server reported: '+json.error);
                    return false;
                }
                planData.defgd = defgd;
                loadRotationTable("plan-rotations");
            },
            error: function(jqXHR, textStatus, errorThrown) {
                alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
            }
        });
    }


    function openEditRotDialog(ev) {
        $("#edit-rot-paddock-name").html(ev.data.name);
        $("#edit-rot-start").val(ev.data.start_date);
        $("#edit-rot-end").val(ev.data.end_date);
        $("#edit-rot-o-end").val(ev.data.end_date);
        $("#edit-rot-min-gd").val(ev.data.actmingd);
        $("#edit-rot-max-gd").val(ev.data.actmaxgd);
        $("#edit-rot-user-gd").val(ev.data.grazing_days);
        $("#edit-rot-o-user-gd").val(ev.data.grazing_days);
        $("#edit-rot-rid").val(ev.data.rid);
        $("#edit-rotation-dialog").dialog('open');
    }

    function buildRoationTable(tableid, jdata) {
        /*
         json = {data: [{
            rid: ...,
            padid: ...,
            name: ...,
            actmingd: ...,
            actmaxgd: ...,
            grazing_days: ...,
            start_date: ...,
            end_date: ...,
            conflicts: ...,
            locked: ...
            }, ...  ]
        */
        var tableId = '#' + tableid;
        var tbody = $(tableId + ' tbody');
        $(".clickToEdit").off('click');
        $(tbody).empty();
        $.each(jdata, function(index, value) {
            var classes = [];
            if (value.conflicts !== "0")
                classes.push("conflict");
            var drag = "dragHandle";
            if (value.locked == "t") {
                classes.push("nodrop nodrag");
                drag = "noDragHandle";
            }
            else
                classes.push("clickToEdit");
            var addclasses = " class=\"" + classes.join(' ') + "\"";
            var url = "holistic-plan-plot.php?plan="+planId+"&padid="+
                value.padid+"&start="+value.start_date+"&end="+
                value.end_date;
            var newRow = $( "<tr id=\""+tableid+"-row-"+value.rid+"\"" +
                addclasses+">" +
                "<td class=\""+drag+"\">&nbsp;</td>" +
                "<td class=\"clickable\">"+value.name+"</td>" +
                "<td align=\"right\" class=\"clickable\">"+value.grazing_days+"</td>" +
                "<td class=\"clickable\">"+value.start_date+"</td>" +
                "<td class=\"clickable\">"+value.end_date+"</td>" +
                "<td class=\"clickable\"><img src=\""+url+"\"></td>" +
                "</tr>\n" );
            if (value.locked != "t")
                $(newRow).on('click', 'td.clickable', value, openEditRotDialog);
            $(tbody).append(newRow);
        });
        $(tableId).tableDnD({
            onDrop: function(table, row) {
                var rot = $(tableId).tableDnDSerialize();
                var data = 'mode=update&plan='+planId+'&'+rot;
                $.ajax({
                    url: 'holistic-paddock-plan.php',
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(json) {
                        if (json.error) {
                            alert('Error server reported: '+json.error);
                            return false;
                        }
                        // save the data
                        rotationPlan = json.data;
                        // update the able
                        buildRoationTable(tableid, rotationPlan)
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
                    }
                });
            },
            dragHandle: ".dragHandle",
        });
        $(tableId + " tr").hover(function() {
            if (! $(this.cells[0]).hasClass('noDragHandle'))
                $(this.cells[0]).addClass('showDragHandle');
        }, function() {
            $(this.cells[0]).removeClass('showDragHandle');
        });
    }


    function loadRotationTable(tableid) {
        if (planId === null) return false;

        $("#planning-calendar").html("<img src=\"holistic-plan-plot.php?mode=header&plan="+planId+"\">");

        var data = 'mode=list&plan='+planId;
        $.ajax({
            url: 'holistic-paddock-plan.php',
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(json) {
                if (json.error) {
                    alert('Error server reported: '+json.error);
                    return false;
                }
                // save the data
                rotationPlan = json.data;
                // update the able
                buildRoationTable(tableid, rotationPlan)
            },
            error: function(jqXHR, textStatus, errorThrown) {
                alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
            }
        });
    }


    function editRotationEndChanged() {
        var end  = $("#edit-rot-end").val();
        var oend = $("#edit-rot-o-end").val();
        var gd   = $("#edit-rot-user-gd").val();

        var a = moment(end);
        var b = moment(oend);
        var days = a.diff(b, 'days');

        if (intVal(gd) + days > 0) {
            $("#edit-rot-user-gd").val(intVal(gd)+days);
            $("#edit-rot-o-user-gd").val(intVal(gd)+days);
        }
        else
            $("#edit-rot-end").val(oend);

        return false;
    }


    function editRotationGdChanged() {
        var gd   = $("#edit-rot-user-gd").val();
        var ogd   = $("#edit-rot-o-user-gd").val();
        gd = intVal(gd);
        ogd = intVal(ogd);
        var delta = gd - ogd;
        if (delta != 0) {
            var end = $("#edit-rot-end").val();
            var a = moment(end).add(delta, 'days');
            $("#edit-rot-end").val(a.format('YYYY-MM-DD'));
            $("#edit-rot-o-end").val(a.format('YYYY-MM-DD'));
        }
        else
            $("#edit-rot-user-gd").val(ogd);

        return false;
    }


    function rotationInit() {
        $("#edit-rotation-dialog").dialog({
            autoOpen: false,
            modal: true,
            minWidth: 315,
            closeOnEscape: true,
            close: function( event, ui ) {
                $("edit-rot-paddock-name").html('');
                $("edit-rot-start").val('');
                $("edit-rot-end").val('');
                $("edit-rot-min-gd").val('');
                $("edit-rot-max-gd").val('');
                $("edit-rot-user-gd").val('');
            },
            buttons: {
                "Cancel": function() {
                    $(this).dialog("close");
                },
                "Save": function() {
                    var rid   = $("#edit-rot-rid").val();
                    var end   = $("#edit-rot-end").val();
                    var gd    = $("#edit-rot-user-gd").val();
                    var oend  = $("#edit-rot-o-end").val();
                    var ogd   = $("#edit-rot-o-user-gd").val();
                    if (end == oend && gd == ogd) {
                        $(this).dialog("close");
                        return;  // nothing changed
                    }

                    var data = "mode=updategd&plan="+planId+"&rid="+rid+"&end="+end+"&gd="+gd+"&oend="+oend+"&ogd="+ogd;
                    $.ajax({
                        url: 'holistic-paddock-plan.php',
                        type: 'POST',
                        data: data,
                        dataType: 'json',
                        success: function(json) {
                            if (json.error) {
                                alert('Error server reported:'+json.error);
                                return;
                            }
                            // save the data
                            rotationPlan = json.data;
                            // update the able
                            buildRoationTable("plan-rotations", rotationPlan)
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
                        }
                    });
                    $(this).dialog("close");
                }
            }
        });     // edit-rotation-dialog
    }

