
    function toggleEvent(obj, selector) {
        // when we click the checkbox if toggles
        // so we need to check based on previous state
        if ( obj.prop( "checked" ) ) {
            obj.prop( "checked", true );
        }
        else {
            obj.prop( "checked", false );
        }
    }

    function updateCalFiltRegexp() {
        var values = [];
        $(".cal-select input").each(function() {
            if ($(this).prop( "checked" ))
                values.push($(this).val());
        });
        calSelected = new RegExp('(' + values.join('|') + ')');
    }


    function checkFilters(obj) {
        switch (obj.val()) {
            case "all":   // All Events
                $("#calendar-filter input").prop( "checked", true );
                $(obj).prop( "checked", false );
                break;
            case "2":   // Management
                toggleEvent(obj, '.evt-social');
                break;
            case "3":   // Planning
                toggleEvent(obj, '.evt-planning');
                break;
            case "4":   // Livestock
                toggleEvent(obj, '.evt-livestock');
                break;
            case "5":   // Paddock
                toggleEvent(obj, '.evt-paddock');
                break;
            case "6":   // Exclusion
                toggleEvent(obj, '.evt-exclusion');
                break;
            case "7":   // Rotation
                toggleEvent(obj, '.evt-rotation');
                break;
            case "8":   // Monitor
                toggleEvent(obj, '.evt-monitor');
                break;
        }
        updateCalFiltRegexp();
        $("#calendar").fullCalendar('refetchEvents');
        return false;
    }

    function formatDate(moment) {
        if (moment == null) return null;
        return moment.format("YYYY-MM-DD HH:mm:ss");
    }

    function revertFunc() {
        if ($('#calendar').fullCalendar('getView').intervalStart === null)
            $('#calendar').fullCalendar('today');
        else
            $('#calendar').fullCalendar('refetchEvents');
    }

    function openRotationDialog(event) {
        if (event.locked == 't')
            $("#rotation-title").css('color', 'rgb(255,0,0)');
        else
            $("#rotation-title").css('color', 'rgb(0,0,0)');
        $("#rotation-title").html(event.title);
        $("#rotation-planid").val(planId);
        $("#rotation-rid").val(event.id);
        $("#rotation-rid2").val(event.id2);
        $("#rotation-plan-date").html(event.start.format("YYYY-MM-DD"));
        if (event.locked == 't') {
            $("#rotation-act-date").val(event.start.format("YYYY-MM-DD"));
            $("#rotation-act-date").prop('disabled', true);
        }
        else {
            $("#rotation-act-date").val('');
            $("#rotation-act-date").datepicker('setDate', event.start.format("YYYY-MM-DD"));
            $("#rotation-act-date").prop('disabled', false);
            $("#rotation-act-date").datepicker('hide');
        }
        $("#rotation-forage-taken").val('M');
        $("#rotation-growth-rate").val('S');
        $("#rotation-error").prop( 'checked', false );
        $("#rotation-comments").val('');
        $("#actual-rotation-dialog").dialog("open");
    }

    function openEventDialog(event) {
        var types = {
            S: '"Calender" tab',
            P: '"Planning" tab -> [Edit Plan] button',
            H: '"Planning" -> "Define the Herd(s)" in the herd table',
            A: '"Planning" -> "Define the Herd(s)" in the animal table',
            E: '"Planning" -> "Select Paddocks and Exclusions" in the Exclusions and Special Attention table',
            R: '"Planning" -> "Plot Grazings on Calendar"',
            M: '"Planning" -> "Implement the Plan"'
        };

        // make sure all the buttons are enabled
        $('.ui-dialog button:nth-child(2)').button('enable');
        $('.ui-dialog button:nth-child(3)').button('enable');

        if (event.className == 'evt-social') {
            $("#event-dialog div.notice").html('');
        }
        else if (event.className == 'evt-rotation') {
                openRotationDialog(event);
                return;
        }
        else {
            // disable the save and delete buttons for these events
            // and display a read only notice
            $('.ui-dialog button:nth-child(2)').button('disable');
            $('.ui-dialog button:nth-child(3)').button('disable');
            $("#event-dialog div.notice").html('<p>Notice this is read-only. This event can only be changed from where it was created. See: '+types[event.type]+'</p>');
        }
        var end;
        // load the dialog with the evant values and open it
        $('#ed-id').val(event.id);
        $('#ed-title').val(event.title);
        $('#ed-start').val(formatDate(event.start));
        if (event.allDay)
            end = event.end.clone().subtract(1, 'days');
        else
            end = event.end;
        $('#ed-end').val(formatDate(end));
        $('#ed-allday').checked = event.allDay;
        $('#ed-class').val(event.className);
        $('#ed-class').trigger('change');
        $('#ed-description').val(event.description);
        $('#ed-refid').val(event.refid);

        // save the current values so we can validate the changes
        pickerOld = { start: event.start, end: end, refid: event.refid };
        $("#event-dialog").dialog("open");
    }


    function holisticCalendarInit() {

        updateCalFiltRegexp();

        $('#ed-class').change(function() {
            $(this).removeClass($(this).attr('class'))
                   .addClass($(":selected", this).attr('class'));
        });

        $("#actual-rotation-dialog").dialog({
            autoOpen: false,
            modal: true,
            closeOnEscape: true,
            buttons: {
                "Cancel": function() {
                    $(this).dialog("close");
                },
                "Save": function() {
                    var plan     = $("#rotation-planid").val();
                    var rid      = $("#rotation-rid").val();
                    var rid2     = $("#rotation-rid2").val();
                    var title    = $("#rotation-title").text();
                    var pdate    = $("#rotation-plan-date").text();
                    var adate    = $("#rotation-act-date").val();
                    var forage   = $("#rotation-forage-taken").val();
                    var growth   = $("#rotation-growth-rate").val();
                    var error    = $("#rotation-error").prop( "checked" );
                    var comments = $("#rotation-comments").val();

                    var data = 'mode=actual&plan='+plan+'&rid='+rid+'&rid2='+rid2+'&title='+title+'&pdate='+pdate+'&adate='+adate+'&forage='+forage+'&growth='+growth+'&error='+error+'&comments='+comments;

                    $.ajax({
                        url: "holistic-paddock-plan.php",
                        type: 'POST',
                        data: data,
                        success: function(json) {
                            if (json.error) {
                                alert('Error server reported:'+json.error);
                                return;
                            }
                            $('#calendar').fullCalendar('refetchEvents');
                            alert('Done');
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            alert( "ERROR:\n" + textStatus +
                                   "\n" + errorThrown);
                        }
                    });
                    $(this).dialog("close");
                }
            }
        });

        $("#event-dialog").dialog({
            autoOpen: false,
            modal: true,
            closeOnEscape: true,
            buttons: {
                "Cancel": function() {
                    $(this).dialog("close");
                },
                "Save Changes": function() {
                    var data;
                    var id     = $('#ed-id').val();
                    var title  = $('#ed-title').val();
                    var start  = $('#ed-start').val();
                    var end    = $('#ed-end').val();
                    var allday = $('#ed-allday').checked?'t':'f';
                    var eclass = $('#ed-class').val();
                    var desc   = $('#ed-description').val();
                    var refid  = $('#ed-refid').val();
                    if (end.length)
                        end = moment(end)
                            .add(1, 'days')
                            .format("YYYY-MM-DD HH:mm:ss");

                    if (id === "")
                        data = 'mode=add&title='+title+'&start='+start+'&end='+end+'&allday='+allday+'&description='+desc+'&classname='+eclass;
                    else
                        data = 'mode=update&id='+id+'&title='+title+'&start='+start+'&end='+end+'&allday='+allday+'&description='+desc+'&classname='+eclass+'&refid='+refid;
                        
                    $.ajax({
                        url: 'holistic-calendar.php',
                        type: 'POST',
                        data: data,
                        dataType: 'json',
                        success: function(json) {
                            if (json.error) {
                                alert('Error server reported:'+json.error);
                                return;
                            }
                            $('#calendar').fullCalendar( 'removeEvents', id );
                            $('#calendar').fullCalendar( 'renderEvent', json, false);
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            alert( "ERROR:\n" + textStatus +
                                   "\n" + errorThrown);
                        }
                    });
                    $(this).dialog("close");
                },
                "Delete Event": function() {
                    var id = $('#ed-id').val();
                    var refid  = $('#ed-refid').val();
                    if ( refid.length > 0 ) {
                        alert("This event was added from the Livestock or\n" +
                              "Land Management tabs. Please remove for that\n" +
                              "page.");
                        return;
                    }
                    $.ajax({
                        url: 'holistic-calendar.php',
                        data: 'mode=delete&id=' + id,
                        type: 'POST',
                        dataType: 'json',
                        success: function(json) {
                            if (json.error) {
                                alert('Error server reported:'+json.error);
                                return;
                            }
                            $('#calendar').fullCalendar( 'removeEvents', id );
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            alert( "ERROR:\n" + textStatus +
                                   "\n" + errorThrown);
                        }
                    });
                    $(this).dialog("close");
                }
            }
        });

        $(".ed-date-picker").datepicker({
            dateFormat: "yy-mm-dd",
            appendText: "(yyyy-mm-dd)",
            changeYear: true,
            onSelect: function(dateText, inst) {
                // get the duration of the old event
                var delta = pickerOld.end.subtract(pickerOld.start);
                if (this.id == "ed-start") {
                    // if the old end is before the new start
                    if (pickerOld.end.isBefore(moment(dateText))) {
                        // then the new end is the new start plus the duration
                        var newEnd = moment(dateText).add(delta);
                        // update everything with the new value(s)
                        $("#ed-end").val( formatDate(newEnd) );
                        pickerOld = { start: moment(dateText), end: newEnd };
                    }
                }
                else if (this.id == "ed-end") {
                    // if the new end is before the ols start
                    if (moment(dateText).isBefore(pickerOld.start)) {
                        // the new start will be the new end minus the duration
                        var newStart = moment(dateText).subtract(delta);
                        // update everything with the new value(s)
                        $('#ed-start').val( formatDate(newStart) );
                        pickerOld = { start: newStart, end: moment(dateText) };
                    }
                }
            }
        });

        $('#calendar').fullCalendar({
            editable: true,
            //eventLimit: true, // allow "more" link when too many events
            eventDurationEditable: true,
            eventStartEditable: true,
            forceEventsDuration: true,

            header: {
                left: 'prevYear,prev,next,nextYear today',
                center: 'title',
                right: 'month,basicWeek,basicDay'
            },

            eventSources: [
                {
                    url: "holistic-calendar.php",
                    data: function() {
                        return {
                            plan: planId,
                            mode: 'list'
                        };
                    }
                }
            ],

            eventDataTransform: function( feedEvent ) {
                var event = Object.create(feedEvent);
                if( event.allDay || event.allDay === 't' || event.allDay === 'true')
                    event.allDay = true;
                else
                    event.allDay = false;
                if( event.editable && ( event.editable === 'f' || event.editable === 'false' ))
                    event.editable = false;
                else
                    event.editable = true;
                if( event.start == event.end && event.allDay ) {
                    event.end = moment(event.end)
                                .add(1, 'days')
                                .format("YYYY-MM-DD");
                }
                if( event.className == 'evt-social' )
                    event.type = 'S';
                return event;
            },

            eventRender: function(evt, el) {
                if (! evt.className.join().match(calSelected)) return false;
                return true;
            },

            eventClick: function(event) {
                openEventDialog(event);
            },

            selectable: true,
            selectHelper: true,
            select: function(start, end) {
                openEventDialog({
                    id: '',
                    title: '',
                    start: start,
                    end: end,
                    allDay: true,
                    description: '',
                    className: 'evt-social',
                    refid: '',
                    type: 'S'
                });

                $('#calendar').fullCalendar('unselect');
            },

            eventDrop: function(event, delta) {
                if (!delta || event.type != 'S') {
                    revertFunc();
                    return false;
                }
                var yr = delta._data.years;
                var mon = delta._data.months;
                var day = delta._data.days;
                var hr = delta._data.hours;
                var min = delta._data.minutes;
                var sec = delta._data.seconds;
                var id = event.id;
                var refid = event.refid;
                $.ajax({
                    url: 'holistic-calendar.php',
                    data: 'mode=move&id='+event.id+'&y='+yr+'&mo='+mon+'&d='+day+'&h='+hr+'&m='+min+'&s='+sec+'&refid='+refid,
                    type: 'POST',
                    dataType: 'json',
                    success: function(json) {
                        if (json.error)
                            alert('Error server reported:'+json.error);
                        $('#calendar').fullCalendar( 'removeEvents', id );
                        $('#calendar').fullCalendar( 'renderEvent', json, false);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert( "ERROR:\n" + textStatus +
                               "\n" + errorThrown);
                    }
                });
            },

            eventResize: function(event, delta, revertFunc) {
                if (!delta || event.type != 'S') {
                    revertFunc();
                    return false;
                }
                var yr = delta._data.years;
                var mon = delta._data.months;
                var day = delta._data.days;
                var hr = delta._data.hours;
                var min = delta._data.minutes;
                var sec = delta._data.seconds;
                var id = event.id;
                var refid = event.refid;
                $.ajax({
                    url: 'holistic-calendar.php',
                    data: 'mode=moveend&id='+event.id+'&y='+yr+'&mo='+mon+'&d='+day+'&h='+hr+'&m='+min+'&s='+sec+'&refid='+refid,
                    type: 'POST',
                    dataType: 'json',
                    success: function(json) {
                        if (json.error)
                            alert('Error server reported:'+json.error);
                        $('#calendar').fullCalendar( 'removeEvents', id );
                        $('#calendar').fullCalendar( 'renderEvent', json, false);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert( "ERROR:\n" + textStatus +
                               "\n" + errorThrown);
                    }
                });
            }
        });     // $('#calendar').fullCalendar()

        $('#calendar').fullCalendar('today');
    }     // holisticCalendarInit()
