<?php
    $batchsize = 100;

    $db_login = array_map('rtrim', file('.ht_dblogin'), array_fill(0, 4, "\n"));
    $db = new mysqli($db_login[0], $db_login[1], $db_login[2], $db_login[3]);

    if($db->connect_errno)
    {
        printf('Connect failed: %s\n', $db->connect_error);
        exit();
    }

    $_GET['logfile'] = (int) $_GET['logfile'];

    if(isset($_GET['save_rule']))
    {
        $_GET['active'] = (int) $_GET['active'];
        $_GET['regex'] = addslashes(rawurldecode($_GET['regex']));
        
        if($_GET['save_rule'] == 'new')
        {
            $db->query("INSERT INTO rules(regex, active, logfile_id) VALUES(\"{$_GET['regex']}\", {$_GET['active']}, {$_GET['logfile']})");
            echo $db->insert_id;
        }
        else
        {
            $_GET['save_rule'] = (int) $_GET['save_rule'];
            $db->query("UPDATE rules SET regex = \"{$_GET['regex']}\", active = {$_GET['active']} WHERE id = {$_GET['save_rule']}");
        }

        if($_GET['regex'][0] != '^')
            $_GET['regex'] = '^' . $_GET['regex'];

        $db->query("DELETE FROM offenders WHERE logfile_id = {$_GET['logfile']} AND line REGEXP \"{$_GET['regex']}\"");
        exit();
    }

    if(isset($_GET['delete_rule']))
    {
        $_GET['delete_rule'] = (int) $_GET['delete_rule'];
        $db->query("DELETE FROM rules WHERE id = {$_GET['delete_rule']}");
        exit();
    }

    if(isset($_GET['monitoring']))
    {
        $_GET['monitoring'] = (int) $_GET['monitoring'];
        $db->query("UPDATE logfiles SET monitoring = {$_GET['monitoring']} WHERE id = {$_GET['logfile']}");
        exit();
    }

    if(isset($_GET['add_logfile']))
    {
        $_GET['add_logfile'] = rawurldecode($_GET['add_logfile']);
        $db->query("INSERT INTO logfiles(path, monitoring) values(\"{$_GET['add_logfile']}\", 1)");
        header("location: {$_SERVER['PHP_SELF']}?logfile={$db->insert_id}");
        die();
    }

    if(isset($_GET['delete_logfile']))
    {
        $_GET['delete_logfile'] = (int) $_GET['delete_logfile'];
        $db->query("DELETE FROM logfiles WHERE id = {$_GET['delete_logfile']}");
        $db->query("DELETE FROM rules WHERE logfile_id = {$_GET['delete_logfile']}");
        $db->query("DELETE FROM offenders WHERE logfile_id = {$_GET['delete_logfile']}");

        if($_GET['logfile'] == $_GET['delete_logfile'])
            $_GET['logfile'] = 0;

        header("location: {$_SERVER['PHP_SELF']}");
        die();
    }

    if(isset($_GET['edit_logfile_path']))
    {
        $_GET['edit_logfile_path'] = urldecode($_GET['edit_logfile_path']);
        $db->query("UPDATE logfiles SET path = '{$_GET['edit_logfile_path']}' WHERE id = {$_GET['logfile']}");
        header("location: {$_SERVER['PHP_SELF']}?logfile={$_GET['logfile']}");
        die();
    }

    if(isset($_GET['batch']))
    {
        $_GET['batch'] = (int) $_GET['batch'];
        $_GET['first_id'] = (int) $_GET['first_id'];

        if(!$offenders = $db->query("SELECT id, line, seen FROM offenders WHERE logfile_id = {$_GET['logfile']} AND id <= {$_GET['first_id']} ORDER BY id DESC LIMIT " . ($_GET['batch']*$batchsize) . ",{$batchsize}"))
            die();

        while($offender = $offenders->fetch_assoc())
        {
            echo "<div id=\"offender{$offender['id']}\" class=\"offender\" onclick=\"construct_regex(document.getElementById('line{$offender['id']}').innerText)\">\n";
            echo "<div id=\"seen{$offender['id']}\" class=\"seen\">{$offender['seen']}</div>\n";
            echo "<div id=\"line{$offender['id']}\" class=\"line\"><pre>";
            echo htmlspecialchars($offender['line']);
            echo "</pre></div>\n";
            echo "</div></a>\n";
        }

        $offenders->close();
        die();
    }

    if(!$offenders = $db->query("SELECT id, line, seen FROM offenders WHERE logfile_id = {$_GET['logfile']} ORDER BY id DESC limit 0,{$batchsize}"))
        die('ERROR: ' . $db->error);

    $first_offender_id = $offenders->fetch_assoc()['id'];
    $offenders->data_seek(0);
?>
<!DOCTYPE html>
<html>
    <head>
        <title>Logmonitor</title>
        <meta name="viewport" content="initial-scale=1">
        <style>
            body
            {
                margin:0;
                padding:0;
                font-family:sans-serif;
                font-size:12pt;
                font-weight:normal;
                color:black;
            }

            .button_big
            {
                float:left;
                height:100%;
                font-size:1.3em;
            }

            .regex
            {
                float:left;
                width:calc(100% - 22em);
                font-size:1em;
                height:1em;
                margin-left:0.5em;
            }

            .active
            {
                float:left;
                position:relative;
                top:-0.22em;
                zoom:1.9;
            }

            .box
            {
               left:0;
               width:100%;
            }

            .rules
            {
                left:0;
                width:100%;
                position:fixed;
                top:2em;
                height:2.1em;
                min-height:2.1em;
                max-height:calc(100% - 5em);
                resize:vertical;
                overflow-x:auto;
                overflow-y:scroll;
                background-color:#0f0;
                box-shadow:0 0 1em #000;
                z-index:200;
                padding-top:0.9em;
            }

            .priority
            {
                float:left;
            }
            
            .last_usage
            {
                float:left;
                font-size:0.6em;
                margin-left:0.5em;
            }

            .button
            {
                float:left;
                margin-left:0.5em;
            }
            
            .offenders
            {
                position:absolute;
                left:0;
                top:5em;
                width:100%;
                background-color:#fff;
                z-index:100;
                font-family:monospace;
                padding-top:0.5em;
            }

            .offenders :hover
            {
                background-color:#ff4;
                cursor:pointer;
            }

            .offender
            {
                margin-top:0.2em;
                margin-left:1%;
            }

            .seen
            {
                font-size:0.5em;
            }

            .line
            {
                white-space:nowrap;
                font-size:0.8em;
            }

            pre
            {
                margin:0;
                padding:0;
            }
        </style>
        <script type="text/javascript">
            function try_regex(regex_string)
            {
                if(regex_string.slice(0, 1) != '^')
                    var regex = new RegExp('^' + regex_string);
                else
                    var regex = new RegExp(regex_string);

                var elements = document.getElementsByClassName('line');

                for(var i = 0; i < elements.length; i++)
                {
                    if(regex.test(elements[i].innerText))
                        elements[i].parentNode.style.color = '#000';
                    else
                        elements[i].parentNode.style.color = '#888';
                }

                return false;
            }

            function save_regex(id, regex_string, active)
            {
                document.getElementById('save' + id).style.color = '#888';

                var select_element = document.getElementById('logfile');
                var logfile_id = select_element.value;

                if(active)
                    active = 1;
                else
                    active = 0;

                var xmlhttp = new XMLHttpRequest();
                xmlhttp.onreadystatechange = function()
                    {
                        if(xmlhttp.readyState == 4 && xmlhttp.status == 200)
                        {
                            document.getElementById('save' + id).style.color = '#000';
                            
                            if(regex_string.slice(0, 1) != '^')
                                var regex = new RegExp('^' + regex_string);
                            else
                                var regex = new RegExp(regex_string);

                            var elements = document.getElementsByClassName('line');

                            var to_delete = [];

                            for(var i = 0; i < elements.length; i++)
                            {
                                if(regex.test(elements[i].innerText))
                                    to_delete.push(elements[i].parentNode);
                            }

                            for(var i = 0; i < to_delete.length; i++)
                                to_delete[i].parentNode.removeChild(to_delete[i]);

                            reset_regex(id);
                            
                            if(id == 'new')
                            {
                                rule_id = xmlhttp.responseText;

                                if(active)
                                    var checked = ' checked="checked"';
                                else
                                    var checked = '';

                                rule_html = "<div style=\"clear:both\">\n";
                                rule_html += "<form id=\"form" + rule_id + "\" onsubmit=\"return try_regex(document.getElementById('regex" + rule_id + "').value)\">\n";
                                rule_html += "<input id=\"regex" + rule_id + "\" class=\"regex\" type=\"text\" oninput=\"this.style.backgroundColor = '#fcc'\" value=\"" + regex_string.replace(/"/g, '&quot;') + "\">";
                                rule_html += "<input id=\"active" + rule_id + "\" class=\"active\" type=\"checkbox\"" + checked + ">";
                                rule_html += "<div class=\"priority\">0</div>\n";
                                rule_html += "<div class=\"last_usage\"></div>\n";
                                rule_html += "<input class=\"button\" type=\"submit\" value=\"try\">";
                                rule_html += "<input id=\"save" + rule_id + "\" class=\"button\" type=\"button\" value=\"save\" onclick=\"save_regex(" + rule_id + ", document.getElementById('regex" + rule_id + "').value, document.getElementById('active" + rule_id + "').checked)\">";
                                rule_html += "<input class=\"button\" type=\"reset\" value=\"reset\" onclick=\"reset_regex(" + rule_id + ")\">";
                                rule_html += "<input id=\"delete" + rule_id + "\" class=\"button\" type=\"button\" value=\"delete\" onclick=\"delete_regex(" + rule_id + ")\">";
                                rule_html += "</form>\n";
                                rule_html += "</div>\n";

                                document.getElementById('regexnew').parentNode.parentNode.parentNode.insertAdjacentHTML('beforeend', rule_html);
                            }
                        }
                    }

                var url = "<?=$_SERVER['PHP_SELF']?>?logfile=" + logfile_id + "&save_rule=" + id + "&regex=" + encodeURIComponent(regex_string) + "&active=" + active;
                xmlhttp.open("GET", url, true);
                xmlhttp.send();
            }

            function delete_regex(id)
            {
                var regex_string = document.getElementById('regex' + id).value;

                if(confirm('Delete ' + regex_string + '?'))
                {
                    var delete_element = document.getElementById('delete' + id);
                    delete_element.style.color = '#888';

                    var xmlhttp = new XMLHttpRequest();
                    xmlhttp.onreadystatechange = function()
                        {
                            if(xmlhttp.readyState == 4 && xmlhttp.status == 200)
                            {
                                delete_element.style.color = '#000';
                                delete_element.parentNode.parentNode.removeChild(delete_element.parentNode);
                            }
                        }

                    var url = "<?=$_SERVER['PHP_SELF']?>?delete_rule=" + id;
                    xmlhttp.open("GET", url, true);
                    xmlhttp.send();
                }
            }

            function reset_regex(id)
            {
                document.getElementById('regex' + id).style.backgroundColor = '#fff';

                var elements = document.getElementsByClassName('line');

                for(var i = 0; i < elements.length; i++)
                    elements[i].parentNode.style.color = '#000';
            }

            function select_logfile(choice)
            {
                if(choice == 'new')
                {
                    var path = prompt('Enter logfile path');

                    if(path != null)
                        window.location.href = "<?=$_SERVER['PHP_SELF']?>?add_logfile=" + encodeURIComponent(path);
                }
                else
                    window.location.href = "<?=$_SERVER['PHP_SELF']?>?logfile=" + choice;
            }

            function delete_logfile()
            {
                var select_element = document.getElementById('logfile');
                var value = select_element.value;
                var text = select_element.options[select_element.selectedIndex].text;
                
                if(value != 'new')
                    if(confirm('Delete ' + text + '?'))
                        window.location.href = "<?=$_SERVER['PHP_SELF']?>?delete_logfile=" + value;
            }

            function edit_logfile()
            {
                var select_element = document.getElementById('logfile');
                var value = select_element.value;
                var text = select_element.options[select_element.selectedIndex].text;
                
                if(value != 'new')
                {
                    var path = prompt('Enter new logfile path', text.slice(0, text.lastIndexOf(' ')));

                    if(path != null)
                        window.location.href = "<?=$_SERVER['PHP_SELF']?>?logfile=" + value + "&edit_logfile_path=" + encodeURIComponent(path);
                }
            }

            function toggle_logfile()
            {
                var select_element = document.getElementById('logfile');
                var logfile_id = select_element.value;

                if(logfile_id != 'new')
                {
                    var monitoring_element = document.getElementById('monitoring');
                    var monitoring = monitoring_element.value;

                    if(monitoring == 'on')
                        monitoring = 0;
                    else
                        monitoring = 1;

                    monitoring_element.style.color = '#888';

                    var xmlhttp = new XMLHttpRequest();
                    xmlhttp.onreadystatechange = function()
                        {
                            if(xmlhttp.readyState == 4 && xmlhttp.status == 200)
                            {
                                if(monitoring == 0)
                                {
                                   monitoring_element.value = 'off';
                                   select_element.options[select_element.selectedIndex].style.color = '#888';
                                }
                                else
                                {
                                    monitoring_element.value = 'on';
                                    select_element.options[select_element.selectedIndex].style.color = '#000';
                                }

                                monitoring_element.style.color = '#000';
                            }
                        }

                    var url = "<?=$_SERVER['PHP_SELF']?>?logfile=" + logfile_id + "&monitoring=" + monitoring;
                    xmlhttp.open("GET", url, true);
                    xmlhttp.send();
                }
            }

            function construct_regex(offender)
            { 
                offender = offender.replace(/([{}[\]()^$.|*+?\\])/g, "\\$1");
                document.getElementById('regexnew').value = offender;
            }

            function select_newregex()
            {
                regexnew = document.getElementById('regexnew');
                start = regexnew.selectionStart;
                end = regexnew.selectionEnd;
                regex = regexnew.value;

                replacement = '.{' + (end-start) + '}';

                regexnew.value = regex.substring(0, start) + replacement + regex.substring(end);
                regexnew.selectionStart = start;
                regexnew.selectionEnd = start + replacement.length;
            }

            var batch = 1;
            var loading = false;

            function more_logfile()
            {
                loading = true;
                var xmlhttp = new XMLHttpRequest();
                xmlhttp.onreadystatechange = function()
                    {
                        if(xmlhttp.readyState == 4 && xmlhttp.status == 200)
                        {
                            document.getElementById('offenders').insertAdjacentHTML('beforeend', xmlhttp.responseText);
                            batch++;
                            loading = false;
                        }
                    }

                var url = "<?=$_SERVER['PHP_SELF']?>?logfile=<?=$_GET['logfile']?>&first_id=<?=$first_offender_id?>&batch=" + batch;
                xmlhttp.open("GET", url, true);
                xmlhttp.send();
            }

            function scroll()
            {
                var pos = document.body.scrollTop + window.innerHeight;
                var page_length = document.body.scrollHeight;

                if(pos > page_length - window.innerHeight && !loading)
                    more_logfile();
            }
        </script>
    </head>
    <body onscroll="scroll()" onload="document.getElementById('regexnew').focus()">
        <div class="box" style="position:fixed; top:0; height:2em; background-color:#f00; box-shadow:0 0 1em #000; z-index:300">
            <?php
                if(!$logfiles = $db->query('SELECT logfiles.id AS id, path, monitoring, COUNT(offenders.id) AS num_offenders FROM logfiles LEFT OUTER JOIN offenders ON logfiles.id = offenders.logfile_id GROUP BY logfiles.id ORDER BY monitoring DESC, num_offenders DESC'))
                    die('ERROR: ' . $db->error);

                echo "<select id=\"logfile\"class=\"button_big\" style=\"width:calc(100% - 8em)\" size=\"1\" onchange=\"select_logfile(this.value)\" onclick=\"if(this.options.length == 1) select_logfile(this.value)\">\n";
                echo "<option value=\"new\"> add new monitor";

                while($logfile = $logfiles->fetch_assoc())
                {
                    if($logfile['id'] == $_GET['logfile'])
                    {
                        $selected = ' selected="selected"';

                        if($logfile['monitoring'])
                            $status = 'on';
                        else
                            $status = 'off';
                    }

                    else
                        $selected = '';

                    if($logfile['monitoring'] != 0)
                        $grey = '';
                    else
                        $grey = ' style="color:#888"';

                    echo "<option value=\"{$logfile['id']}\"{$selected}{$grey}> {$logfile['path']} ({$logfile['num_offenders']})\n";
                }

                echo "</select>\n";
                echo "<input onclick=\"delete_logfile()\" class=\"button_big\" style=\"width:3.5em\" type=\"button\" value=\"delete\">\n";
                echo "<input onclick=\"edit_logfile()\" class=\"button_big\" style=\"width:2.5em\" type=\"button\" value=\"edit\">\n";
                echo "<input id=\"monitoring\" onclick=\"toggle_logfile()\" class=\"button_big\" style=\"width:2em\" type=\"button\" value=\"{$status}\">\n";

                $logfiles->close();
            ?>
        </div>
        <div class="rules">
            <?php
                if(!$rules = $db->query("SELECT id, regex, priority, active, last_usage FROM rules WHERE logfile_id = {$_GET['logfile']} ORDER BY active DESC, priority DESC, last_usage DESC"))
                    die('ERROR: ' . $db->error);

                echo "<div>\n";
                echo "<form id=\"formnew\" onsubmit=\"return try_regex(document.getElementById('regexnew').value)\">\n";
                echo "<input id=\"regexnew\" class=\"regex\" type=\"text\" onselect=\"select_newregex()\" autocomplete=\"off\" value=\"\">";
                echo "<input id=\"activenew\" class=\"active\" type=\"checkbox\" checked=\"checked\">";
                echo "<input class=\"button\" type=\"submit\" value=\"try\">";
                echo "<input id=\"savenew\" class=\"button\" type=\"reset\" value=\"save\" onclick=\"save_regex('new', document.getElementById('regexnew').value, document.getElementById('activenew').checked)\" accesskey=\"S\">";
                echo "<input class=\"button\" type=\"reset\" value=\"reset\" onclick=\"reset_regex('new')\">";
                echo "</form>\n";
                echo "</div>\n";

                while($rule = $rules->fetch_assoc())
                {
                    if($rule['active'] != 0)
                        $checked = ' checked="checked"';
                    else
                        $checked = '';

                    echo "<div style=\"clear:both\">\n";
                    echo "<form id=\"form{$rule['id']}\" onsubmit=\"return try_regex(document.getElementById('regex{$rule['id']}').value)\">\n";
                    echo "<input id=\"regex{$rule['id']}\" class=\"regex\" type=\"text\" oninput=\"this.style.backgroundColor = '#fcc'\" autocomplete=\"off\" value=\"" . htmlspecialchars($rule['regex']) . "\">";
                    echo "<input id=\"active{$rule['id']}\" class=\"active\" onchange=\"document.getElementById('regex{$rule['id']}').style.backgroundColor = '#fcc'\" type=\"checkbox\"{$checked}>";
                    echo "<div class=\"priority\">" . round($rule['priority'], 1) . "</div>\n";
                    echo "<div class=\"last_usage\">" . str_replace(' ', '<br>', $rule['last_usage']) . "</div>\n";
                    echo "<input class=\"button\" type=\"submit\" value=\"try\">";
                    echo "<input id=\"save{$rule['id']}\" class=\"button\" type=\"button\" value=\"save\" onclick=\"save_regex({$rule['id']}, document.getElementById('regex{$rule['id']}').value, document.getElementById('active{$rule['id']}').checked)\">";
                    echo "<input class=\"button\" type=\"reset\" value=\"reset\" onclick=\"reset_regex({$rule['id']})\">";
                    echo "<input id=\"delete{$rule['id']}\" class=\"button\" type=\"button\" value=\"delete\" onclick=\"delete_regex({$rule['id']})\">";
                    echo "</form>\n";
                    echo "</div>\n";
                }

                echo "</select>\n";
                $rules->close();
            ?>
        </div>
        <div id="offenders" class="offenders">
            <?php
                while($offender = $offenders->fetch_assoc())
                {
                    echo "<div id=\"offender{$offender['id']}\" class=\"offender\" onclick=\"construct_regex(document.getElementById('line{$offender['id']}').innerText)\">\n";
                    echo "<div id=\"seen{$offender['id']}\" class=\"seen\">{$offender['seen']}</div>\n";
                    echo "<div id=\"line{$offender['id']}\" class=\"line\"><pre>";
                    echo htmlspecialchars($offender['line']);
                    echo "</pre></div>\n";
                    echo "</div></a>\n";
                }

                $offenders->close();
                $db->close();
            ?>
        </div>
    </body>
</html>
