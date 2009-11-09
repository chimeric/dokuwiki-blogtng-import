#!/usr/bin/php -d short_open_tag=on
<?php
if ('cli' != php_sapi_name()) die();

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../').'/');
require_once DOKU_INC.'inc/init.php';
require_once DOKU_INC.'inc/auth.php';
require_once DOKU_INC.'inc/common.php';
require_once DOKU_INC.'inc/cliopts.php';
require_once DOKU_INC.'inc/fulltext.php';

// overwrite comment subscription setting
global $conf;
$conf['plugin']['blogtng']['comments_subscription'] = 0;

function usage() {
    print '
NAME
    blogtngimport.php

AUTHOR
    Michael Klier <chi@chimeric.de>

DESCRIPTION
    Utility script to import blog entries/comments/linkbacks which were
    created using the old DokuWiki blog plugin suite:
        
        http://dokuwiki.org/plugin:blog
        http://dokuwiki.org/plugin:discussion
        http://dokuwiki.org/plugin:linkback

OPTIONS
    -h --help       Displays this help text.

    -d --dryun      Performs a dryrun without importing anything.

    -u --user       set wiki user for whom the entries should be imported.

                    The user must be an existing login name.
    -a --author     Explicitely set author name to be used for import.

    -m --mail       Explicitely set mail address to be used for import.

    -p --page       Only import a single wiki page into a blog.

    -n --ns         Import a whole namespace into a blog.

    -b --blog       The blog the pages should be imported in. Must be an existing blogtng blog.

';
}

function getSuppliedArgument($OPTS, $short, $long) {
    $arg = $OPTS->get($short);
    if ( is_null($arg) ) {
        $arg = $OPTS->get($long);
    }
    return $arg;
}

// Setup options
$OPTS = Doku_Cli_Opts::getOptions(
    __FILE__,
    'hdu:a:p:b:n:m:',
    array(
        'help',
        'dryrun',
        'user=',
        'author=',
        'page=',
        'blog=',
        'ns=',
        'mail=',
        )
);

// script variables
$opt['user']   = '';
$opt['mail']   = '';
$opt['author'] = '';
$opt['ns']     = '';
$opt['page']   = '';
$opt['blog']   = 'default';
$opt['dryrun'] = '';

// check options
if($OPTS->isError()) {
    ptln($OPTS->getMessage());
    exit(1);
}

if($OPTS->has('h') or $OPTS->has('help')) {
    usage();
    exit(0);
}

if($OPTS->has('m') or $OPTS->has('mail')) {
    $opt['mail'] = getSuppliedArgument($OPTS, 'm', 'mail');
}

if($OPTS->has('a') or $OPTS->has('author')) {
    $opt['author'] = getSuppliedArgument($OPTS, 'a', 'author');
}

if($OPTS->has('n') or $OPTS->has('ns')) {
    $opt['ns'] = getSuppliedArgument($OPTS, 'n', 'ns');
}

if($OPTS->has('d') or $OPTS->has('dryrun')) {
    $opt['dryrun'] = true;
}

if($OPTS->has('p') or $OPTS->has('page')) {
    if(!empty($opt['ns'])) {
        ptln('ERROR: You cannot use [-p|--page] and [-n|--ns] at the same time!');
        useage('ns');
        exit(1);
    }
    $opt['page'] = getSuppliedArgument($OPTS, 'p', 'page');
}

if(empty($opt['ns']) && empty($opt['page'])) {
    ptln('ERROR: You have to specify a namespace or page');
    usage('p');
    exit(1);
}

if($OPTS->has('u') or $OPTS->has('user')) {
    $opt['user'] = getSuppliedArgument($OPTS, 'u', 'user');
    $userdata = $auth->getUserData($opt['user']);
    if(!empty($userdata)) {
        $opt['author'] = $userdata['name'];
        $opt['mail']   = $userdata['mail'];
    }
}

if(empty($opt['author']) || empty($opt['mail'])) {
    ptln('ERROR: Failed to set userdata. Make sure the user exists or set --mail and --author explicitely.');
    exit(1);
}

// load helper plugins
$entryhelper   =& plugin_load('helper', 'blogtng_entry');
$taghelper     =& plugin_load('helper', 'blogtng_tags');
$commenthelper =& plugin_load('helper', 'blogtng_comments');

if($OPTS->has('b') or $OPTS->has('blog')) {
    $opt['blog'] = getSuppliedArgument($OPTS, 'b', 'blog');
    $blogs = $entryhelper->get_blogs();
    unset($blogs[0]); // remove first empty blog
    if(!in_array($opt['blog'], $blogs)) {
        ptln('ERROR: blog "' . $opt['blog'] . '" doesn\'t exist!');
        ptln('Available blogs are: ' . implode(', ', $blogs));
        exit(1);
    }
}

if(!empty($opt['ns'])) {
    $pages = ft_pageLookup($opt['ns'], false);
    if(!empty($pages)) {
        ptln('Found ' . count($pages) . ' pages...');
    } else {
        ptln('ERROR: No pages found given namespace: ' . $opt['ns']);
        exit(1);
    }
}

// FIXME showpages ask to continue?

if(!empty($opt['page'])) {
    if(page_exists($opt['page'])) {
        $pages[] = $opt['page'];
        ptln('Found one page...');
    } else {
        ptln('ERROR: Page ' . $opt['page'] . ' does not exist!');
        exit(1);
    }
}

ptln('Beginning import ...');
$garbage   = array();
foreach($pages as $page) {
    $tags      = array();
    $comments  = array();
    $linkbacks = array();
    $entry     = array();
    $meta      = array();

    ptln('importing ' . $page . ' ...');

    // load metadata
    $meta = p_get_metadata($page);

    // prepare entry
    $entry['pid']     = md5(cleanID($page));
    $entry['page']    = $page;
    $entry['title']   = $meta['title'];
    $entry['created'] = $meta['date']['created'];
    $entry['lastmod'] = $meta['date']['modified'];
    $entry['author']  = $opt['author'];
    $entry['login']   = $opt['user'];
    $entry['mail']    = $opt['mail'];
    $entry['blog']    = $opt['blog'];

    // save entry
    $entryhelper->entry = $entryhelper->prototype(); // reset entry
    $entryhelper->set($entry);

    if($opt['dryrun']) {
        ptln('INFO: Would save ' . $entry['page'] . ' into blog ' . $entry['blog']);
    } elseif(!$entryhelper->save()) {
        ptln('ERROR: Failed to save blog entry ' . $entry['page']);
        exit(1);
    }

    // handle tags
    $tags = $meta['subject'];
    if(!empty($tags)) {
        $taghelper->pid = $entry['pid'];
        $taghelper->set($tags);
        if($opt['dryrun']) {
            ptln('INFO: Would save tags for ' . $entry['page'] . ': ' . implode(', ', $taghelper->tags));
        } else {
            $taghelper->save();
        }

    }


    // handle comments
    $cfile = metaFN($entry['page'], '.comments');
    if(@file_exists($cfile)) {
        array_push($garbage, $cfile);
        $comments_meta = unserialize(io_readFile($cfile, false));
        $comments = $comments_meta['comments'];
        if(!empty($comments)) {
            if($opt['dryrun']) {
                ptln('INFO: Would save ' . count($comments) . ' comment(s) for ' . $entry['page']);
            }
            foreach($comments as $comment) {

                $cmt = array();
                $cmt['status'] = ($comment['show']) ? 'visible' : 'hidden';
                $cmt['pid']    = $entry['pid'];
                $cmt['ip']     = '';
                $cmt['source'] = 'comment';
                $cmt['text']   = $comment['raw'];

                // check comment format
                if(is_array($comment['user'])) {
                    $cmt['name']  = $comment['user']['name'];
                    $cmt['mail']  = $comment['user']['mail'];
                    $cmt['web']   = ($comment['user']['url']) ? $comment['user']['url'] : '';
                } else {
                    $cmt['name'] = $comment['name'];
                    $cmt['mail'] = $comment['mail'];
                }

                if(is_array($comment['date'])) {
                    $cmt['created'] = $comment['date']['created'];
                } else {
                    $cmt['created'] = $comment['date'];
                }

                if($opt['dryrun']) {
                    ptln('INFO: Would save following comment for ' . $entry['page']);
                    print_r($cmt);
                } else {
                    $commenthelper->save($cmt);
                }
            }
        }
    }

    // get linkbacks
    $lfile = metaFN($page['id'], '.linkbacks');
    if(@file_exists($lfile)) {
        array_push($garbage, $lfile);
        $linkbacks = unserialize(io_readFile($lfile, false));
        if(!empty($linkbacks['receivedpings'])) {
            if($opt['dryrun']) {
                pltn('INFO: Would save ' . count($linkbacks['receivedpings']) . ' linkback(s) for' . $entry['page']);
            }
            foreach($linkbacks['receivedpings'] as $linkback) {
                $lb = array();
                $lb['status']  = ($linkback['show']) ? 'visible' : 'hidden';
                $lb['pid']     = $entry['pid'];
                $lb['ip']      = '';
                $lb['source']  = $linkback['type'];
                $lb['text']    = $linkback['raw_excerpt'];
                $lb['web']     = $linkback['url'];
                $lb['avatar']  = $linkback['favicon'];
                $lb['name']    = $linkback['blog_name'];
                $lb['created'] = $linkback['received'];
                $lb['mail']    = '';

                if($opt['dryrun']) {
                    ptln('INFO: Would save following linkback for ' . $entry['page']);
                    print_r($lb);
                } else {
                    $commenthelper->save($lb);
                }
            }
        }
    }

    // strip syntax
    if($opt['dryrun']) {
        ptln('INFO: Would attempt to strip discussion/linkback plugin syntax from wiki pages!');
    } else {
        $text = '';
        $text = io_readFile(wikiFN($page['id']));
        $pattern = array('/~~DISCUSSION.*?~~/', '/{{tag>.*?}}/', '/~~LINKBACK~~/');
        $text = preg_replace($pattern, '', $text);
        io_saveFile(wikiFN($page['id']), $text);
    }
}

ptln('Removing old meta files...');
if($opt['dryrun']) {
    ptln('INFO: Would attempt to remove ' . count($garbage) . ' old meta files!');
    print_r($gargabe);
} else {
    foreach($garbage as $file) {
        ptln('removing ' . $file);
        unlink($file);
    }
}

ptln('Bye!');

// vim:ts=4:sw=4:et:enc=utf-8:
