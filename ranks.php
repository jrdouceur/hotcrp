<?php
// ranks.php -- HotCRP reviewer paper-ranking page, written by John R. Douceur
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/paperlist.inc");
require_once("Code/search.inc");
require_once("Code/tags.inc");
require_once("Code/options.inc");

$Opt["stylesheets"][] = "dragstyle.css"; // style sheet for client-side drag/drop elements

// ensure that a PC member is signed into a conference that is using ranking
$Me->goIfInvalid();
$Me->goIfNotPC();
if (!$Conf->setting("tag_rank"))
	$Me->goAlert(false, "This conference is not using ranking.");

// if ranking is disallowed, let the user know
$pastDeadline = !$Conf->timeReviewPaper($Me->isPC, true, true);
if ($pastDeadline && !$Conf->deadlinesAfter("rev_open"))
    $Conf->infoMsg("The site is not open for review.");
else if ($pastDeadline)
    $Conf->infoMsg("The <a href='" . hoturl("deadlines") . "'>deadline</a> for ranking papers has passed. " . ($Me->privChair ?
        "You are allowed to change ranks only because you are chair." :
        "Dragging has been disabled on this page." ));

// note whether the user can rank all papers
$canReviewAll = $Conf->setting("pcrev_any") > 0 || $Me->privChair;

function tableRowForPaper($dbRow, $assigned) // one <tr/> element for the unranked table
{
    global $Me;

    // record paper's ID and title, for referential convenience
    $thisPaperId = $dbRow->paperId;
    $thisPaperTitle = $dbRow->title;

    // the HTML anchor element for the paper is the paper ID
    $thisPaperAnchor = "<a href='" . hoturl("paper", "p=$thisPaperId") . "'>$thisPaperId</a>";

    // this paper's class partly depends on whether it is assigned
    $thisPaperClass = "dragpaper nonghost " . ($assigned ? "assigned" : "unassigned");

    // return a <tr/> row iff the user is allowed to set this paper's rank
    if ($Me->canSetRank($submittedPaperRow))
    {
        return "<tr><td class='paperslot'><div class='$thisPaperClass' id='paperref$thisPaperId'>#$thisPaperAnchor $thisPaperTitle</div></td></tr>\n";
    }
    else
    {
        return "";
    }
}

function unrankedPaperTableRowsForAssignedPapers() // a string of <tr/> elements for the unranked table, if the user can review only assigned papers
{
    global $Conf, $Me;

    // retrieve an SQL set of all assigned papers, in order of paper ID
    $assignedPapersQuery = $Conf->paperQuery($Me, array("reviewId" => $Me->contactId, "finalized" => 1, "order" => "order by Paper.paperId"));
    $assignedPapersSet = $Conf->qe($assignedPapersQuery, "while selecting papers");

    $unrankedTableRows = "<tr>starting assigned</tr>"; // we'll build this into a series of <tr/> elements for the unranked table

    // iterate through all papers in assignedPaperSet
    for ($assignedPaperRow = edb_orow($assignedPapersSet); $assignedPaperRow != false; $assignedPaperRow = edb_orow($assignedPapersSet))
    {
        $unrankedTableRows .= tableRowForPaper($assignedPaperRow, true); // add <tr/> row to string
    }

    return $unrankedTableRows;
}

function unrankedPaperTableRowsForSubmittedPapers() // a string of <tr/> elements for the unranked table, if the user can review all submitted papers
{
    global $Conf, $Me;

    // retrieve an SQL set of all submitted papers, in order of paper ID
    $submittedPapersQuery = $Conf->paperQuery($Me, array("finalized" => 1, "order" => "order by Paper.paperId"));
    $submittedPapersSet = $Conf->qe($submittedPapersQuery, "while selecting papers");

    // retrieve an SQL set of all assigned papers, in order of paper ID
    $assignedPapersQuery = $Conf->paperQuery($Me, array("reviewId" => $Me->contactId, "finalized" => 1, "order" => "order by Paper.paperId"));
    $assignedPapersSet = $Conf->qe($assignedPapersQuery, "while selecting papers");

    $unrankedTableRows = ""; // we'll build this into a series of <tr/> elements for the unranked table

    // assignedPaperSet is a sublist of submittedPaperSet, so we iterate through the latter and conditionally iterate through the former
    $assignedPaperRow = edb_orow($assignedPapersSet);
    for ($submittedPaperRow = edb_orow($submittedPapersSet); $submittedPaperRow != false; $submittedPaperRow = edb_orow($submittedPapersSet))
    {
        // if this paper's ID matches a paper ID in the assigned set, this paper is assigned
        $thisPaperIsAssigned = $assignedPaperRow != null && $submittedPaperRow->paperId == $assignedPaperRow->paperId;

        // add <tr/> row to string
        $unrankedTableRows .= tableRowForPaper($submittedPaperRow, $thisPaperIsAssigned);

        // if the current paper is assigned, we are done with this assigned row, and we need to start comparing against the subsequent row
        if ($thisPaperIsAssigned)
        {
            $assignedPaperRow = edb_orow($assignedPapersSet);
        }
    }

    return $unrankedTableRows;
}

function unrankedPaperTableRows() // a string of <tr/> elements for the unranked table
{
    global $canReviewAll;

    return $canReviewAll ? unrankedPaperTableRowsForSubmittedPapers() : unrankedPaperTableRowsForAssignedPapers();
}

function invocationsOfChangePaperRank() // a string of JS function invocations for the setInitialPaperRanks() JS function
{
    global $Conf, $Me;

    // the tag used for this reviewer's ranks
    $tag_rank = $Me->contactId . "~" . $Conf->settingText("tag_rank");

    // retrieve an SQL set of all ranked papers, in order of rank
    $rankedPapersQuery = $Conf->paperQuery($Me, array("tagIndex" => $tag_rank, "finalized" => 1, "order" => "order by tagIndex"));
    $rankedPapersSet = $Conf->qe($rankedPapersQuery, "while selecting papers");

    $changeInvocations = ""; // we'll build this into a series of JS function invocations

    // iterate through ranked papers in order
    $rankForPaper = 1;
    for ($rankedPaperRow = edb_orow($rankedPapersSet); $rankedPaperRow != false; $rankedPaperRow = edb_orow($rankedPapersSet))
    {
        // record paper's ID, for convenience
        $thisPaperId = $rankedPaperRow->paperId;

        // add a JS function invocation to the string we're building iff this paper is ranked by this user
        if ($Me->canSetRank($rankedPaperRow) && $rankedPaperRow->tagIndex !== null)
        {
            $changeInvocations .= "changePaperFromOldRankToNewRank(\$\$('paperref$thisPaperId'), 0, $rankForPaper);\n";
            $rankForPaper++;
        }
    }

    return $changeInvocations;
}

// Standard HotCRP header; this function also inserts the <body> tag
$Conf->header("Paper Ranks", "paperranks", actionBar());

$Conf->infoMsg("<p>Use this page to rank the papers you have read.
Papers in the Unranked pane are ordered by paper ID; papers in the ranked pane are ordered by rank.
To set ranks, drag papers from the Unranked pane to the Ranked pane, or drag papers up and down within the Ranked pane.
To clear a rank, drag a paper back to the Unranked pane.</p>" .
($canReviewAll ? "<p>The radio buttons select which papers are displayed in the Unranked pane only; the Ranked pane always displays every paper you have ranked.
Papers you have been assigned to review are shown in blue; all others are shown in gray.</p>" : "") .
"<p>You can also upload rankings via the <a href='" . hoturl("offline") . "'>offline</a> page.</p>");

$Conf->infoMsg("<p>
Note: This page enforces strict sequential ranking.
If you have used the offline page to insert gaps or to mark papers as equal rank, making any change with this page will cause your gaps and equalities to be lost.
</p>");

// end of the main PHP block; below this point is HTML and JavaScript with embedded PHP, up until the footer stuff at the very end
?>

<center>
    <div id="ghostpaper" class="dragpaper">this is the ghostpaper div</div>

    <br />
    <form id="rankform" action="<?= hoturl_post("search", "ajax=1&amp;tagact=1&amp;tag=%7E{$Conf->settingText('tag_rank')}") ?>" method="post">
        <input type="button" name="revertchanges" value="Undo all changes" onclick="revertChanges()" />
        <input type="button" name="undochange" value="Undo change" onclick="undoChange()" />
        <input type="button" name="redochange" value="Redo change" onclick="redoChange()" />
        <input type="button" name="savechanges" value="Save all changes" onclick="saveChanges()" />
        <input type="hidden" name="tagtype" value="" />
        <input type="hidden" name="p" value="" />
    </form>
    <br /><br />

    <table>
        <tbody>
            <tr>
                <th><u>Unranked</u></th>
                <th id="rankdivgap"></th>
                <th><u>Ranked</u></th>
            </tr>
            <tr>
                <td class="subheading">
                    <?php if ($canReviewAll) { ?>
                        <center>
                            <form action="">
                                <table>
                                    <tr align="left">
                                        <td><input type="radio" name="paperstoshow" id="showassignedpapers" checked="checked"/></td>
                                        <td><label for="showassignedpapers">Show your assigned reviews</label></td>
                                    </tr>
                                    <tr align="left">
                                        <td><input type="radio" name="paperstoshow" id="showsubmittedpapers" /></td>
                                        <td><label for="showsubmittedpapers">Show all submitted papers</label></td>
                                    </tr>
                                </table>
                            </form>
                        </center>
                    <?php } ?>
                </td>
                <td></td>
                <td class="subheading"><center>(order from best to worst)</center></td>
            </tr>
            <tr>
                <td class="dragcontainerholder">
                    <div class="dragpapercontainer" id="unrankedouterdiv">
                        <div id="unrankedinnerdiv">
                            <table class="dragpapertable" id="unrankedtable">
                                <tbody id="unrankedtbody">
                                    <?= unrankedPaperTableRows() ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </td>
                <td></td>
                <td class="dragcontainerholder">
                    <div class="dragpapercontainer" id="rankedouterdiv">
                        <div id="rankedinnerdiv">
                            <table class="dragpapertable" id="rankedtable">
                                <tbody id="rankedtbody">
                                </tbody>
                            </table>
                        </div>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</center>

<table id="hiddentable">
    <tbody id="hiddentbody">
    </tbody>
</table>

<script language="JavaScript" type="text/javascript">
// when this page is generated by PHP, the ranked tbody is empty
// this function is programmatically generated by PHP to move papers with initial ranks to the ranked tbody
function setInitialPaperRanks()
{
    // these calls must be ordered by increasing rank
    <?= invocationsOfChangePaperRank() ?>
}
</script>

<!--browser version compatibility crap-->

<script language="JavaScript" type="text/javascript"> var leftClick = 0; </script>

<!--[if lt IE 9]>
    <script language="JavaScript" type="text/javascript">
        leftClick = 1;
        document.onselectstart = function () { return false; }; // prevent text selection
    </script>
<![endif]-->

<script language="JavaScript" type="text/javascript">

// global variables

var undoLog = new Array(); // undo log of drag/drop operations
var redoLog = new Array(); // redo log of drag/drop operations

// setup initial state of ranking page; function is called at page load time
function initRankingPage()
{
    // initially, there are no changes to post, to undo, or to redo
    $$("rankform").revertchanges.disabled = true;
    $$("rankform").undochange.disabled = true;
    $$("rankform").redochange.disabled = true;
    $$("rankform").savechanges.disabled = true;

    <?php if (!$pastDeadline || $Me->privChair) { // drag operations are allowed only if reviewing is allowed ?>

    // bind mousedown events to handler
    $$("unrankedinnerdiv").onmousedown = startDrag;
    $$("rankedinnerdiv").onmousedown = startDrag;

    <?php } ?>

    // bind onresize event to handler
    window.onresize = setOuterDivHeights;

    // create a closure for updating the display of unranked papers according to the radio buttons
    createRadioButtonChangeClosure();

    // move papers with initial ranks to the ranked tbody
    setInitialPaperRanks();
}

// the height of the outer div may change when the window size changes or when an inner div changes
function setOuterDivHeights()
{
    // window height is window.innerHeight or document.documentElement.clientHeight, depending on browser
    var windowHeight = window.innerHeight != undefined ? window.innerHeight : document.documentElement.clientHeight;

    // the outer divs are limited to the window height minus a buffer, so they can always fit in the the visible window area
    $$("unrankedouterdiv").style.height = Math.min(windowHeight - 100, $$("unrankedinnerdiv").offsetHeight + 10) + "px";
    $$("rankedouterdiv").style.height = Math.min(windowHeight - 100, $$("rankedinnerdiv").offsetHeight + 10) + "px";
}

// the height of the ranked inner div can change when the radio-button state changes or when a paper changes between ranked and hidden unranked
function setRankedInnerDivHeight()
{
    // the inner div's height is sufficient to exactly accommodate all papers
    $$("rankedinnerdiv").style.height = $$("unrankedtbody").offsetHeight + $$("rankedtbody").offsetHeight + 20 + "px";

    setOuterDivHeights(); // the outer div heights are dependent upon the inner div heights
}

// create a closure for updating the display of unranked papers according to the radio buttons
function createRadioButtonChangeClosure()
{
    // this array will be indexed with a toString'ed version of a class array
    var heightForUnrankedDivWithClasses = [];

    // a little local function since we're doing this a couple of times
    function setHeightForUnrankedDivArrayForClasses(classArray)
    {
        // temporarily update the papers visible in the unranked div according to this class array
        updateUnrankedPapersAccordingToClassArray(classArray);

        // the unranked inner div's height is sufficient to exactly accommodate all papers available given this class array
        heightForUnrankedDivWithClasses[classArray.toString()] = $$("unrankedtbody").offsetHeight + 20 + "px";
    }

    // there are only two radio-button options, so the simplest way to explore all cases is to inline them
    setHeightForUnrankedDivArrayForClasses([ ]);
    setHeightForUnrankedDivArrayForClasses([ "assigned" ]);

    // now we can define the function that uses heightForUnrankedDivWithClasses[] to set the unranked inner div height
    function updateUnrankedPaperContainerToMatchRadioButtons()
    {
        // get the state of the radio buttons into an array
        var classArray = radioButtonClassArray();

        // set the unranked inner div height according to the class array
        $$("unrankedinnerdiv").style.height = heightForUnrankedDivWithClasses[classArray.toString()];

        // update which unranked papers are displayed
        updateUnrankedPapersAccordingToClassArray(classArray);

        // set the ranked inner div heights, since they depend on the papers displayed in the uranked inner div
        setRankedInnerDivHeight(); // note: this function calls setOuterDivHeights(), so we don't need to call it explicitly
    }

    <?php if ($canReviewAll) { ?>

        // the above function must be called whenever the radio-button state changes
        $$("showassignedpapers").onclick = updateUnrankedPaperContainerToMatchRadioButtons;
        $$("showsubmittedpapers").onclick = updateUnrankedPaperContainerToMatchRadioButtons;

    <?php } ?>

    // the above function must also be called at page load time, so we do it now
    updateUnrankedPaperContainerToMatchRadioButtons();
}

function disableButtonsAndDragging()
{
    // disable the revert, undo, redo, and save buttons
    $$("rankform").revertchanges.disabled = true;
    $$("rankform").undochange.disabled = true;
    $$("rankform").redochange.disabled = true;
    $$("rankform").savechanges.disabled = true;

    // disable dragging
    $$("unrankedinnerdiv").onmousedown = null;
    $$("rankedinnerdiv").onmousedown = null;
}

function enableButtonsAndDragging()
{
    if (undoLog.length > 0) // the undo log is non-empty
    {
        // there are changes that can be reverted, undone, or saved
        $$("rankform").revertchanges.disabled = false;
        $$("rankform").undochange.disabled = false;
        $$("rankform").savechanges.disabled = false;
    }

    if (redoLog.length > 0) // the redo log is non-empty
    {
        // there are changes that can be redone
        $$("rankform").redochange.disabled = false;
    }

    // enable dragging
    $$("unrankedinnerdiv").onmousedown = startDrag;
    $$("rankedinnerdiv").onmousedown = startDrag;
}

// start a paper drag operation; this is bound to the onmousedown event of the ranked and unranked inner divs
function startDrag(ev)
{
    // event is either the parameter or window.event, depending on browser
    ev = ev != null ? ev : window.event;

    if (ev.button == leftClick)
    {
        // identify the source element, which is ev.target or ev.srcElement, depending on browser
        var clickedElement = ev.target != null ? ev.target : ev.srcElement;

        if (classStringIncludesAllClasses(clickedElement.className, [ "dragpaper", "nonghost" ])) // the clicked element is a paper
        {
            // hide the clicked element
            clickedElement.style.visibility = "hidden";

            // copy position, text, and class info from the clicked element into the ghost element, and unhide the ghost
            elPos = elementPosition(clickedElement);
            $$("ghostpaper").style.left = extractNumber(elPos.x) + 'px';
            $$("ghostpaper").style.top = extractNumber(elPos.y) + 'px';
            $$("ghostpaper").className = clickedElement.className.replace("nonghost", ""); // copy all classes except nonghost
            $$("ghostpaper").innerHTML = clickedElement.innerHTML;
            $$("ghostpaper").style.visibility = "visible";

            // create a closure for the onmousemove, onmouseup, and onkeydown events
            createMouseClosure(clickedElement, mousePosition(ev));

            // this prevents screen flashing in Chrome due to instantaneous text selection/deselection
            return false;
        }
    }
}

// create a closure for the onmousemove, onmouseup, and onkeydown events
function createMouseClosure(clickedElement, mousePos)
{
    // closure variables
    var sourceElement = null; // the element the user intends to move
    var sourceStart = null;   // the position of the source element when the drag starts
    var mouseStart = null;    // the position of the mouse when the drag starts
    var sourceRank = 0;      // the rank of the source element, 0 if unranked
    var currentRank = 0;     // the current rank of the dragged element, 0 if unranked

    // record a reference to the clicked element
    sourceElement = clickedElement;

    // record the source element position and mouse start position
    sourceStart = elementPosition(sourceElement);
    mouseStart = mousePos;

    if (isPositionWithinRect(mouseStart, boundaryOfDiv($$("rankedouterdiv")))) // mouse is in ranked div
    {
        sourceStart.y -= $$("rankedouterdiv").scrollTop; // adjust the recorded source position to account for scroll in the ranked div
        sourceRank = indexOfPaper(sourceElement) + 1; // record source's starting rank
    }
    else // mouse is in unranked div
    {
        sourceStart.y -= $$("unrankedouterdiv").scrollTop; // adjust the recorded source position to account for scroll in the unranked div
        sourceRank = 0; // record that the source started unranked
    }

    // the drag hasn't really started yet, so current equals source
    currentRank = sourceRank;

    // set the onmousemove function for the drag
    // we can't bind this to the ghost paper, because fast mouse motion can cause the mouse to move beyond the edge of the element
    document.onmousemove = function (ev) {
        // event is either the parameter or window.event, depending on browser
        ev = ev != null ? ev : window.event;

        // make the ghost element's offset from its start position match the mouse's offset from its start position
        var mousePos = mousePosition(ev);
        $$("ghostpaper").style.left = (mousePos.x - mouseStart.x + sourceStart.x) + 'px';
        $$("ghostpaper").style.top = (mousePos.y - mouseStart.y + sourceStart.y) + 'px';

        var newRank = null;
        if (isPositionWithinRect(mousePos, boundaryOfDiv($$("unrankedouterdiv")))) // the mouse is within the unranked div
        {
            newRank = 0; // change the paper's rank to 0 to indicate unranked
        }
        else if (isPositionWithinRect(mousePos, boundaryOfDiv($$("rankedouterdiv")))) // the mouse is within the ranked div
        {
            // determine which row the mouse is over, and add 1 to turn this into a rank
            // if the paper is coming from the unranked table, this row can be one beyond the current max row
            newRank = indexOfMouseInRankedTable(ev, currentRank == 0) + 1;
        }
        else // the mouse is not within either the unranked or ranked divs
        {
            newRank = sourceRank; // change the paper back to its starting rank
        }

        // update the tables to reflect the change in rank
        changePaperFromOldRankToNewRank(sourceElement, currentRank, newRank);

        // update the current rank
        currentRank = newRank;
    }

    // set the onmouseup function for the drag
    $$("ghostpaper").onmouseup = completeDrag;

    // set a function to capture the keydown event, for detecting ESC hit while dragging
    document.onkeydown = function (ev)
    {
        if (sourceElement != null && ev.keyCode == 27) // we are currently dragging and the ESC is hit
        {
            changePaperFromOldRankToNewRank(sourceElement, currentRank, sourceRank); // change the paper back to its starting rank
            completeDrag(ev);
        }
    }

    // complete a drag operation
    function completeDrag(ev)
    {
        if (currentRank != sourceRank) // the element has moved
        {
            // disable user input until the update is complete
            disableButtonsAndDragging();

            // truncate the redo log
            while (redoLog.length > 0)
            {
                redoLog.pop();
            }

            // record drag/drop in undo log
            var dragdrop = { paperDiv: sourceElement, sourceRank: sourceRank, destRank: currentRank };
            undoLog.push(dragdrop);

            // send rank info from the ranked table to the server
            submitRanksToServer();
        }

        // hide the ghost element and clear its state
        $$("ghostpaper").style.visibility = "hidden";
        $$("ghostpaper").style.left = 0;
        $$("ghostpaper").style.top = 0;
        $$("ghostpaper").innerHTML = '';

        // unhide the source element; use "inherit" instead of "visible" so this will get hidden if moved inside the hidden table
        sourceElement.style.visibility = "inherit";

        // disable the onmousemove, onmouseup, and onkeydown functions; this should also enable the closure to be freed
        document.onmousemove = null;
        $$("ghostpaper").onmouseup = null;
        document.onkeydown = null;

        // cancel text selection
        document.body.focus();
    }
}

// update the unranked and hidden tables according to an array of paper classes
function updateUnrankedPapersAccordingToClassArray(classArray)
{
    // record the counts of rows in each tbody
    var unrankedRowCount = $$("unrankedtbody").getElementsByTagName('TR').length;
    var hiddenRowCount = $$("hiddentbody").getElementsByTagName('TR').length;

    // starting with the first row in each tbody
    var unrankedIndex = 0;
    var hiddenIndex = 0;

    // continue until both tbodies are exhausted
    while (unrankedIndex < unrankedRowCount || hiddenIndex < hiddenRowCount)
    {
        // get the papers referred to by each index, using null if the index is beyond the end of its table
        var unrankedPaper = (unrankedIndex < unrankedRowCount) ? $$("unrankedtbody").rows[unrankedIndex].cells[0].firstChild : null;
        var hiddenPaper = (hiddenIndex < hiddenRowCount) ? $$("hiddentbody").rows[hiddenIndex].cells[0].firstChild : null;

        // the paper with the lower reference number is the one we consider relocating
        var candidatePaper = (refNumOfPaper(unrankedPaper) < refNumOfPaper(hiddenPaper)) ? unrankedPaper : hiddenPaper;

        if (classStringIncludesAllClasses(candidatePaper.className, classArray)) // paper belongs in the unranked tbody
        {
            if (candidatePaper == hiddenPaper) // paper is currently in the hidden tbody
            {
                // insert a new row in the unranked tbody
                $$("unrankedtbody").insertRow(unrankedIndex);
                $$("unrankedtbody").rows[unrankedIndex].appendChild(candidatePaper.parentNode);
                unrankedRowCount++; // increment the row count to reflect the insertion

                // delete the row that previously held the paper
                $$("hiddentbody").deleteRow(hiddenIndex);
                hiddenRowCount--; // decrement the row count to reflect the deletion
            }
            unrankedIndex++; // the paper at unrankedIndex is properly in the unranked tbody, so move on to the next one
        }
        else // paper belongs in the hidden tbody
        {
            if (candidatePaper == unrankedPaper) // paper is currently in the unranked tbody
            {
                // insert a new row in the hidden tbody
                $$("hiddentbody").insertRow(hiddenIndex);
                $$("hiddentbody").rows[hiddenIndex].appendChild(candidatePaper.parentNode);
                hiddenRowCount++; // increment the row count to reflect the insertion

                // delete the row that previously held the paper
                $$("unrankedtbody").deleteRow(unrankedIndex);
                unrankedRowCount--; // decrement the row count to reflect the deletion
            }
            hiddenIndex++; // the paper at hiddenIndex is properly in the unranked tbody, so move on to the next one
        }
    }
}

// revert all changes in papers' ranks
function revertChanges()
{
    // disable user input until the update is complete
    disableButtonsAndDragging();

    // undo every change in the undo log
    while (undoLog.length > 0)
    {
        // pop the drag/drop from the undo log
        var dragdrop = undoLog.pop();

        // move the element from the destination back to the source
        changePaperFromOldRankToNewRank(dragdrop.paperDiv, dragdrop.destRank, dragdrop.sourceRank);

        // record drag/drop in redo log
        redoLog.push(dragdrop);
    }

    // send rank info from the ranked table to the server
    submitRanksToServer();
}

// undo a change in a paper's rank
function undoChange()
{
    // disable user input until the update is complete
    disableButtonsAndDragging();

    // pop the drag/drop from the undo log
    var dragdrop = undoLog.pop();

    // move the element from the destination back to the source
    changePaperFromOldRankToNewRank(dragdrop.paperDiv, dragdrop.destRank, dragdrop.sourceRank);

    // record drag/drop in redo log
    redoLog.push(dragdrop);

    // send rank info from the ranked table to the server
    submitRanksToServer();
}

// redo a change in a paper's rank
function redoChange()
{
    // disable user input until the update is complete
    disableButtonsAndDragging();

    // pop the drag/drop from the redo log
    var dragdrop = redoLog.pop();

    // move the element from the source to the destination
    changePaperFromOldRankToNewRank(dragdrop.paperDiv, dragdrop.sourceRank, dragdrop.destRank);

    // record drag/drop in undo log
    undoLog.push(dragdrop);

    // send rank info from the ranked table to the server
    submitRanksToServer();
}

// save all changes in papers' ranks
function saveChanges()
{
    // disable user input until the update is complete
    disableButtonsAndDragging();

    // clear the undo and redo logs
    while (undoLog.length > 0)
    {
        undoLog.pop();
    }
    while (redoLog.length > 0)
    {
        redoLog.pop();
    }

    // send rank info from the ranked table to the server
    submitRanksToServer();
}

// the workhorse function that performs the manipulations to change a paper's rank
function changePaperFromOldRankToNewRank(paperDiv, oldRank, newRank)
{
    if (oldRank == newRank) // the paper is already in the intended position
    {
        return;
    }

    // compute where to insert the new row
    var toTBody = null;
    var toIndex = null;
    if (newRank == 0) // paper is moving to the unranked or hidden tbody
    {
        // set destination tbody according to whether the radio buttons indicate the element should be shown
        var classArray = radioButtonClassArray();
        toTBody = classStringIncludesAllClasses(paperDiv.className, classArray) ? $$("unrankedtbody") : $$("hiddentbody");

        // compute the index of the paper based on its paper number
        var paperRefNum = refNumOfPaper(paperDiv);
        toIndex = unrankedIndexInTBodyForPaperWithNumber(toTBody, paperRefNum);
    }
    else // paper is moving to the ranked tbody
    {
        toTBody = $$("rankedtbody");
        if (oldRank > 0 && oldRank < newRank) // if the paper is in the ranked tbody and currently above its new rank
        {
            // when we remove the paper from its old positon, the papers will slide up by one, so we have to add 1 to the index
            // however, indices are 0-origin but ranks are 1-origin, so this cancels out the above addition
            toIndex = newRank; // + 1 - 1
        }
        else // otherwise
        {
            // new row will be inserted at the new rank, subtracting 1 to turn a 1-origin rank into a 0-origin index
            toIndex = newRank - 1;
        }
    }

    // record the from row and tbody for use in the removeChild call below
    var fromRow = paperDiv.parentNode.parentNode; // the grandparent of the paper's div is a tr element
    var fromTBody = fromRow.parentNode; // the parent of the row is a tbody

    // insert a row in the appropriate tbody, and relocate the paper div's parent td element to this row
    toTBody.insertRow(toIndex);
    toTBody.rows[toIndex].appendChild(paperDiv.parentNode);

    // delete the row that previously held the paper
    fromTBody.removeChild(fromRow);

    // since we may have changed the size of the ranked tbody, we should recalculate the height of the paper containers
    setRankedInnerDivHeight();
}

// add a new hidden input elemnt to the rankform, so it can be sent to the server
function addNamedValueToForm(name, value)
{
    // create new input element
    var inputEl = document.createElement("input");

    // type is hidden, name is specified by argument
    inputEl.setAttribute("type", "hidden");
    inputEl.setAttribute("name", name);

    // append new input element to form
    $$("rankform").appendChild(inputEl);

    // value of input element is specified by argument
    $$("rankform").elements[name].value = value;
}

// send rank info from the ranked table to the server
function submitRanksToServer()
{
    // build string of ranked papers in order
    var row = $$("rankedtbody").rows[0];
    var rankedPaperString = "";
    while (row != null)
    {
        var refNum = refNumOfPaper(row.cells[0].firstChild);
        rankedPaperString += " " + refNum;

        // get the next tr sibling, which may require looping through whitespace first
        do { row = row.nextSibling; } while (row != null && row.tagName != "TR");
    }

    if (rankedPaperString == "") // no papers in ranked table
    {
        // delete all rank tags
        $$("rankform").tagtype.value = "d"; // Delete
        $$("rankform").p.value = "all"; // special value indicating all papers
    }
    else // at least one paper in ranked table
    {
        // add ranked papers in order
        $$("rankform").tagtype.value = "sos"; // Set Ordered Sequence (I think)
        $$("rankform").p.value = rankedPaperString.substr(1); // remove leading space
    }

    // submit form to server
    Miniajax.submit("rankform", function (rv) { enableButtonsAndDragging(); } );
}

// functions without DOM side effects

function boundaryOfDiv(div)
{
    var divPos = elementPosition(div);
    var divBottom = divPos.y + div.offsetHeight - 1;
    var divRight = divPos.x + div.offsetWidth - 1;
    return { top: divPos.y, bottom: divBottom, left: divPos.x, right: divRight };
}

function isPositionWithinRect(pos, rect)
{
    return pos.x >= rect.left
        && pos.x <= rect.right
        && pos.y >= rect.top
        && pos.y <= rect.bottom;
}

function indexOfMouseInRankedTable(ev, allowToExtend)
{
    // PRECONDITION:  mouse is within ranked div

    // count of rows in the ranked table
    var rowCount = $$("rankedtbody").getElementsByTagName('TR').length;

    // if table is empty, always return index 0; otherwise, the math below computes a NaN
    if (rowCount == 0)
    {
        return 0;
    }

    // vertical distance in pixels from the top of the tbody to the mouse
    var mousePosY = mousePosition(ev).y - elementPosition($$("rankedtbody")).y + $$("rankedouterdiv").scrollTop;

    // the div starts higher than the table body, but we don't want to return a negative number
    mousePosY = Math.max(extractNumber(mousePosY), 0);

    // height of the tbody element
    var tableHeight = $$("rankedtbody").offsetHeight;

    // assume the rows are evenly spaced along the tbody (this is not correct, but it's close enough)
    var row = Math.floor((mousePosY * rowCount) / tableHeight);

    // the div ends lower than the table body, so we need to limit the return value
    if (allowToExtend)
    {
        row = Math.min(row, rowCount); // return value of rowCount indicates a new row, beyond current table length
    }
    else
    {
        row = Math.min(row, rowCount - 1);
    }

    return row;
}

// find the index of a paper within a table
function indexOfPaper(el)
{
    var row = el.parentNode.parentNode; // the grandparent of the paper's div is a tr element

    var rowCount = -1; // initialize the count at -1, so that we return a zero-orgin index

    while (row != null) // loop until no more rows
    {
        // increment the row count
        rowCount++;

        // get the previous tr sibling, which may require looping through whitespace first
        do { row = row.previousSibling; } while (row != null && row.tagName != "TR");
    }

    return rowCount;
}

// find the proper location in an unranked table for a paper based on its reference number
function unrankedIndexInTBodyForPaperWithNumber(destTBody, searchRefNum)
{
    // start with the first row in the tbody
    var row = destTBody.rows[0];
    var rowIndex = 0;

    // loop through rows, stopping when we run out of rows or we hit a row with a higher ID value than we're searching for
    while (row != null && refNumOfPaper(row.cells[0].firstChild) < searchRefNum)
    {
        // get the next tr sibling, which may require looping through whitespace first
        do { row = row.nextSibling; } while (row != null && row.tagName != "TR");

        rowIndex++;
    }

    return rowIndex;
}

// get the reference number of a paper
function refNumOfPaper(el)
{
    if (el == null) // if we get passed a null
    {
        return 32767; // return a paper number that should be higher than any reasonable paper number for a conference
    }
    else // otherwise
    {
        return extractNumber(el.getAttribute("ID").match(/[0-9]+/g)[0]); // the refnum is the numeric portion of the div's ID
    }
}

// get the absolute position of the mouse
function mousePosition(ev)
{
    var mouseX, mouseY;
    if (ev.pageX != null) // for non-IE browsers
    {
        mouseX = ev.pageX;
        mouseY = ev.pageY;
    }
    else // for IE
    {
        mouseX = ev.clientX + document.documentElement.scrollLeft - document.body.clientLeft;
        mouseY = ev.clientY + document.documentElement.scrollTop - document.body.clientTop;
    }
    return { x: mouseX, y: mouseY };
}

// get the absolute position of an element
function elementPosition(el) {
    var left = 0;
    var top = 0;
    while (el != null)
    {
        left += el.offsetLeft;
        top += el.offsetTop;
        el = el.offsetParent;
    }
    return { x: left, y: top };
}

// extract a number from a value
function extractNumber(value)
{
	var n = parseInt(value);
	
	return ( n == null || isNaN(n) ) ? 0 : n;
}

// return an array of classes (strings) that indicate the state of the radio buttons
function radioButtonClassArray()
{
    <?php if ($canReviewAll) { ?>
        return $$("showassignedpapers").checked ? [ "assigned" ] : [ ];
    <?php } else { ?>
        return [ "assigned" ];
    <?php } ?>
}

// determine whether a given class string contains every class in an array
function classStringIncludesAllClasses(actualClassString, testClassArray)
{
    // split the actual class string into array of separate classes; space is the class delimiter
    var actualClassArray = actualClassString.split(" ");

    // loop through each class in the test array
    // this would be simpler if we could just test for actualClassArray.indexOf(testClassArray[testIndex]) < 0, but not supported in IE8
    var testIndex = 0;
    while (testIndex < testClassArray.length)
    {
        // loop through each class in the actual array until we find one that equals the current test class
        var actualIndex = 0;
        while (actualIndex < actualClassArray.length && actualClassArray[actualIndex] != testClassArray[testIndex])
        {
            actualIndex++;
        }
        if (actualIndex == actualClassArray.length) // we did not find an actual class that equals the test class
        {
            return false; // the actual class array (and thus the actual class string) does not include all test classes
        }
        testIndex++;
    }
    return true; // we made it through the loop, so all classes in the test array must be included in the actual string
}

</script>

<?php

$Conf->footerScript("hotcrpLoad(initRankingPage);"); // add initRankingPage() to list of JS functions called at page load time

$Conf->footer();

?>
