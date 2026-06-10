<?php
const FACILITIES = [
    0=>'kern',1=>'user',2=>'mail',3=>'daemon',4=>'auth',5=>'syslog',
    6=>'lpr',7=>'news',8=>'uucp',9=>'cron',10=>'authpriv',11=>'ftp',
    16=>'local0',17=>'local1',18=>'local2',19=>'local3',20=>'local4',
    21=>'local5',22=>'local6',23=>'local7',
];

const SEVERITIES = [
    0=>'Emergency',1=>'Alert',2=>'Critical',3=>'Error',
    4=>'Warning',5=>'Notice',6=>'Info',7=>'Debug',
];

const SEVERITY_CLASSES = [
    0=>'danger',1=>'danger',2=>'danger',3=>'danger',
    4=>'warning',5=>'info',6=>'success',7=>'secondary',
];

const SEVERITY_ICONS = [
    0=>'🔴',1=>'🔴',2=>'🔴',3=>'🟠',
    4=>'🟡',5=>'🔵',6=>'🟢',7=>'⚪',
];

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function severity_badge(int $sev): string {
    $label = SEVERITIES[$sev] ?? $sev;
    $cls   = SEVERITY_CLASSES[$sev] ?? 'secondary';
    $icon  = SEVERITY_ICONS[$sev] ?? '';
    return "<span class=\"badge bg-{$cls}\">{$icon} {$label}</span>";
}

function facility_label(int $fac): string {
    return FACILITIES[$fac] ?? "fac{$fac}";
}

function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return "{$diff}s";
    if ($diff < 3600)   return floor($diff/60).'m';
    if ($diff < 86400)  return floor($diff/3600).'h';
    return floor($diff/86400).'j';
}
