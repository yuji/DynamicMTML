<?php
    if (! $updated ) {
        if ( $first_ts ) {
            $first_ts = start_end_week( $first_ts );
            $first_ts = $first_ts[0];
            $last_ts = start_end_week( $last_ts );
            $last_ts = $last_ts[0];
            $current_ts = $first_ts;
            do {
                $ts_epoch = datetime_to_timestamp( $current_ts );
                $week_number = date( o, $ts_epoch ) . date( W, $ts_epoch );
                $terms = array( 'blog_id' => $blog_id,
                                'class'   => 'entry',
                                'week_number' => $week_number,
                                'status'  => 2 );
                $continue = 0;
                if ( $limit ) {
                    if ( $ts_counter >= $offset ) {
                        if ( $ts_counter < $offset + $limit ) {
                            $continue = 1;
                        } else {
                            break;
                        }
                    }
                } else {
                    $continue = 1;
                }
                if ( $continue ) {
                    $count = $this->count( 'Entry', $terms, array( 'limit' => 1 ) );
                    if ( $count ) {
                        array_push( $rebuild_start_ts, $current_ts );
                    } else {
                        array_push( $delete_start_ts, $current_ts );
                    }
                }
                $current_ts = __get_next_week( $current_ts );
                $ts_counter++;
            }
            while( $current_ts != __get_next_week( $last_ts ) );
        }
    } else {
        if ( $entry ) {
            $changed_entries = array( $entry );
        } else {
            $changed_entries = $this->stash( 'changed_entries' );
        }
        include( 'set-rebuild-start-ts.php' );
    }
    include( 'date-based-archive-publisher.php' );
?>