<?php
# DynamicMTML (C) 2010-2011 Alfasado Inc.
# This program is distributed under the terms of the
# GNU General Public License, version 2.

    require_once( 'MTUtil.php' );
    require_once( 'dynamicmtml.util.php' );
    $terms = array( 'blog_id' => $blog_id,
                    'archive_type' => $at );
    $maps = $this->load( 'TemplateMap', $terms );
    if ( $maps ) {
        $entry_author = array();
        include ( 'get_entry_authors.php' );
        if ( $entry_author ) {
            foreach ( $entry_author as $author ) {
                $first_ts = NULL;
                $last_ts  = NULL;
                if (! $updated ) {
                    list ( $first_entry, $last_entry ) = $this->start_end_entry( $blog, array( 'author' => $author ) );
                    if ( $first_entry ) {
                        $first_ts = __date2ts( $first_entry->authored_on );
                        $last_ts = __date2ts( $last_entry->authored_on );
                    }
                }
                $rebuild_start_ts = array();
                $delete_start_ts = array();
                if (! $updated ) {
                    if ( $first_ts ) {
                        $first_ts = start_end_day( $first_ts );
                        $first_ts = $first_ts[0];
                        $last_ts = start_end_day( $last_ts );
                        $last_ts = $last_ts[0];
                        $current_ts = $first_ts;
                        do {
                            $y_m_d = substr( $current_ts, 0, 4 ) . '-' . substr( $current_ts, 4, 2 ) . '-' . substr( $current_ts, 6, 2 );
                            $terms = array( 'blog_id' => $blog_id,
                                            'class'   => 'entry',
                                            'author_id' => $author->id,
                                            'authored_on' => array( 'like' => $y_m_d . '%' ),
                                            'status'  => 2 );
                            $count = $this->count( 'Entry', $terms, array( 'limit' => 1 ) );
                            if ( $count ) {
                                array_push( $rebuild_start_ts, $current_ts );
                            } else {
                                array_push( $delete_start_ts, $current_ts );
                            }
                            $current_ts = __get_next_day( $current_ts );
                        }
                        while( $current_ts != __get_next_day( $last_ts ) );
                    }
                } else {
                    if ( $entry ) {
                        $changed_entries = array( $entry );
                    } else {
                        $changed_entries = $this->stash( 'changed_entries' );
                    }
                    include( 'Date' . DIRECTORY_SEPARATOR . 'set-rebuild-start-ts.php' );
                }
                $extra_terms = array( 'author_id' => $author->id );
                $fileinfo_object = array( 'author' => $author );
                include( 'Date' . DIRECTORY_SEPARATOR . 'date-based-archive-publisher.php' );
            }
        }
    }
?>