// stuff the planning tab

function stepsToArray() {
    if (planData === null) {
        return [0,0,0,0,0,0,0,0,0,0,0,0,0,0];
    }
    else {
        var steps = planData.steps
            .replace(/[{}]/gm, '')
            .split(',');
        for (var i=0; i<steps.length; i++)
            steps[i] = ((steps[i] === "0") ? 0 : 1);
        return steps;
    }
}


function updateStepsCompleted() {
    var steps = stepsToArray();
    for (var i=0; i<steps.length; i++)
        $("#cstep"+(i+1)).prop("checked", steps[i]==1);
}


function setStep(step, chk) {
    var steps = stepsToArray();
    steps[step-1] = chk ? 1 : 0;
    return steps;
}


function clickStepCompleted(evt, step) {
    var chk = $("#cstep"+step).prop("checked");
    var c = setStep(step, chk);
    var data = 'mode=update&id='+planId+'&steps='+c.join();
    $.ajax({
        url: 'holistic-plan.php',
        type: 'POST',
        data: data,
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                updateStepsCompleted(planData.steps);
                alert('Error server reported: '+json.error);
                return;
            }
            planData = json;
            updateStepsCompleted(planData.steps);
        },
        error: function(jqXHR, textStatus, errorThrown) {
            updateStepsCompleted(planData.steps);
            alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
        }
    });
    var e = evt || window.event;
    e.cancelBubble = true;
    if (e.stopPropagation) e.stopPropagation();
    return false;
}


function saveFactors(id) {
                var factors = $("#"+id).val();
                var data = 'mode=update&id='+planId+'&factors='+factors;

                $.ajax({
                    url: 'holistic-plan.php',
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(json) {
                        if (json.error) {
                            alert('Error server reported: '+json.error);
                            return;
                        }
                        populatePlanList();
                        $("#select-plan").val(json.id);
                        selectPlan(json.id);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
                    }
                });
}

function createPlan() {
    $("#active-plan-id").val('');
    $("#active-plan-name").val('');
    $("#active-plan-year").val('');
    $("#active-plan-start").val('');
    $("#active-plan-end").val('');
    $("#active-plan-factors").val('');
    $("#planning-dialog").dialog('open');
}

function editPlan() {
    if ( planId === '' || planId === null ) {
        alert( "First select a Plan or Create a New plan!" );
    }
    else {
        $("#active-plan-id").val(planId);
        $("#active-plan-name").val(planData.name);
        $("#active-plan-year").val(planData.year);
        $("#active-plan-type").val(planData.type);
        $("#active-plan-start").val(planData.start_date);
        $("#active-plan-end").val(planData.end_date);
        $("#active-plan-factors").val(planData.factors);
        $("#planning-dialog").dialog('open');
    }
}

function setPlan(data) {
    var ptype;
    switch (data.ptype) {
        case 0: case '0': ptype = "Open-Ended"; break;
        case 1: case '1': ptype = "Closed"; break;
        default: ptype = data.ptype;
    }
    $("#span-plan-name").html(data.name);
    $("#span-plan-year").html(data.year);
    $("#span-plan-ptype").html(ptype);
    $("#span-plan-start").html(moment(data.start_date, "YYYY-MM-DD HH:mm:ss")
        .format("YYYY-MM-DD"));
    $("#span-plan-end").html(moment(data.end_date, "YYYY-MM-DD HH:mm:ss")
        .format("YYYY-MM-DD"));
    $("#span-plan-factors").val(data.factors);
    $("#select-plan").val(data.id);
    $("#paddocks-available").DataTable().ajax.url("holistic-paddock.php?mode=avail&year="+data.year).load(reInitTableTools);
    updateStepsCompleted();
    $("#span-cal-title").html(data.name + ': ' + ptype + '- Dates: ' +
        moment(data.start_date, "YYYY-MM-DD HH:mm:ss").format("YYYY-MM-DD") +
        ' - ' +
        moment(data.end_date, "YYYY-MM-DD HH:mm:ss").format("YYYY-MM-DD")
    );
}

function unsetPlan() {
    setPlan({
        id: '',
        year: 'YYYY',
        ptype: 'Open|Closed',
        name: 'Name',
        start_date: 'YYYY-MM-DD',
        end_date: 'YYYY-MM-DD'
    });
    planId = '';
    planData = null;
    herdCnt = 0;
    $("#select-plan").val('');
    $("#planning-tabs").tabs("option", "active", 0);
    loadHerdTableForPlan(planId);
    updateStepsCompleted();
    $("#paddocks-available").DataTable().ajax.url("holistic-paddock.php?mode=avail&year=").load(reInitTableTools);
    $("#span-cal-title").html('');
}

function selectPlan(pid) {
    var data;
    if (pid == -1)
        pid = $("#select-plan").val();

    if (pid === '' || pid === null) {
        unsetPlan();
    }
    else {
        $.ajax({
            url: 'holistic-plan.php',
            type: 'POST',
            "data": 'mode=list&id='+pid,
            dataType: 'json',
            success: function(json) {
                if (json.error) {
                    alert('Error server reported:'+json.error);
                    return;
                }
                planId = json.id;
                if (planId === null || typeof planId == 'undefined') {
                    planId = null;
                    planData = null;
                    unsetPlan();
                }
                else {
                    planData = json;
                    setPlan(planData);
                    loadHerdTableForPlan(planId);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
            }
        });  // $.ajax
    }
}

function populatePlanList() {
    // request a list of plans from the database
    $.ajax({
        url: 'holistic-plan.php',
        type: 'POST',
        data: 'mode=list',
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                alert('Error server reported:'+json.error);
                return;
            }

            $("#select-plan").find( 'option' ).remove().end()
                .append( '<option selected value="">Select or Create a Plan</option>' );
            $.each(json.data, function (i, data) {
                $("#select-plan").append("<option value='"+data.id+"'>"+data.name+"</option>");
            });
        },
        error: function(jqXHR, textStatus, errorThrown) {
            alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
        }
    });  // $.ajax
}


function planningInit() {
    $("#planning-dialog").dialog({
        autoOpen: false,
        modal: true,
        closeOnEscape: true,
        close: function( event, ui ) {
            $("#active-plan-id").val('');
            $("#active-plan-name").val('');
            $("#active-plan-year").val('');
            $("#active-plan-start").val('');
            $("#active-plan-end").val('');
            $("#active-plan-factors").val('');
        },
        buttons: {
            "Cancel": function() {
                $(this).dialog('close');
            },
            "Save Changes": function() {
                var data;
                var id      = $("#active-plan-id").val();
                var name    = $("#active-plan-name").val();
                var year    = $("#active-plan-year").val();
                var ptype   = $("#active-plan-ptype").val();
                var start   = $("#active-plan-start").val();
                var end     = $("#active-plan-end").val();
                var factors = $("#active-plan-factors").val();

                if ( ! name.length || ! year.length ||
                     ! start.length || ! end.length ) {
                    alert("Please enter values for required fields!");
                    return;
                }

                end = moment(end, "YYYY-MM-DD HH:mm:ss")
                    .add(1, 'days').format("YYYY-MM-DD HH:mm:ss");

                if ( id === "" )
                    data = 'mode=add&name='+name+'&year='+year+'&ptype='+ptype+'&start='+start+'&end='+end+'&factors='+factors;
                else
                    data = 'mode=update&id='+id+'&name='+name+'&year='+year+'&ptype='+ptype+'&start='+start+'&end='+end+'&factors='+factors;

                $.ajax({
                    url: 'holistic-plan.php',
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(json) {
                        if (json.error) {
                            alert('Error server reported: '+json.error);
                            return;
                        }
                        populatePlanList();
                        $("#select-plan").val(json.id);
                        selectPlan(json.id);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
                    }
                });
                $(this).dialog('close');
            },
            "Delete Plan": function() {
                $.ajax({
                    url: 'holistic-plan.php',
                    type: 'POST',
                    data: 'mode=delete&id='+planId,
                    dataType: 'json',
                    success: function(json) {
                        if (json.error) {
                            alert('Error server reported: '+json.error);
                            return;
                        }
                        planId = null;
                        planData = null;
                        populatePlanList();
                        $("#select-plan").val('');
                        selectPlan('');
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert( "ERROR:\n" + textStatus + "\n" + errorThrown);
                    }
                });
                $(this).dialog('close');
            }
        }
    }); // planning dialog

    populatePlanList();

}   // planningInit()


