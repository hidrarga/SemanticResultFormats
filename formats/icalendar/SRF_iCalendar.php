<?php
/**
 * Create iCalendar exports
 * @file
 * @ingroup SemanticResultFormats
 */

/**
 * Printer class for creating iCalendar exports
 * 
 * @author Markus Krötzsch
 * @author Denny Vrandecic
 * @author Jeroen De Dauw
 * 
 * @ingroup SemanticResultFormats
 */
class SRFiCalendar extends SMWExportPrinter {
	
	protected $m_title;
	protected $m_description;
	
	protected $m_timezones;

	protected function handleParameters( array $params, $outputmode ) {
		parent::handleParameters( $params, $outputmode );
		
		$this->m_title = trim( $params['title'] );
		$this->m_description = trim( $params['description'] );
	}

	/**
	 * @see SMWIExportPrinter::getMimeType
	 *
	 * @since 1.8
	 *
	 * @param SMWQueryResult $queryResult
	 *
	 * @return string
	 */
	public function getMimeType( SMWQueryResult $queryResult ) {
		return 'text/calendar';
	}

	/**
	 * @see SMWIExportPrinter::getFileName
	 *
	 * @since 1.8
	 *
	 * @param SMWQueryResult $queryResult
	 *
	 * @return string|boolean
	 */
	public function getFileName( SMWQueryResult $queryResult ) {
		if ( $this->m_title != '' ) {
			return str_replace( ' ', '_', $this->m_title ) . '.ics';
		} else {
			return 'iCalendar.ics';
		}
	}

	public function getQueryMode( $context ) {
		return ( $context == SMWQueryProcessor::SPECIAL_PAGE ) ? SMWQuery::MODE_INSTANCES : SMWQuery::MODE_NONE;
	}

	public function getName() {
		return wfMessage( 'srf_printername_icalendar' )->text();
	}

	protected function getResultText( SMWQueryResult $res, $outputmode ) {
		return $outputmode == SMW_OUTPUT_FILE ? $this->getIcal( $res ) : $this->getIcalLink( $res, $outputmode );
	}
	
	/**
	 * Returns the query result in iCal.
	 * 
	 * @since 1.5.2
	 *  
	 * @param SMWQueryResult $res
	 * 
	 * @return string
	 */
	protected function getIcal( SMWQueryResult $res ) {
		global $wgSRFTimezoneTransitions;
		
		$this->m_timezones = new SRFTimezones();
		
		$result = '';
		
		if ( $this->m_title == '' ) {
			global $wgSitename;
			$this->m_title = $wgSitename;
		}
		
		$result .= "BEGIN:VCALENDAR\r\n";
		$result .= "PRODID:-//SMW Project//Semantic Result Formats\r\n";
		$result .= "VERSION:2.0\r\n";
		$result .= "METHOD:PUBLISH\r\n";
		$result .= "X-WR-CALNAME:" . $this->m_title . "\r\n";
		
		if ( $this->m_description !== '' ) {
			$result .= "X-WR-CALDESC:" . $this->m_description . "\r\n";
		}

		$events = '';
		$row = $res->getNext();
		while ( $row !== false ) {
			$events .= $this->getIcalForItem( $row );
			
			$row = $res->getNext();
		}
		
		$this->m_timezones->calcTransitions();
		$result .= $this->m_timezones->getIcalForTimezone();
		
		$result .= $events;
		
		$result .= "END:VCALENDAR\r\n";

		return $result;
	}

	/**
	 * Returns html for a link to a query that returns the iCal.
	 * 
	 * @since 1.5.2
	 *  
	 * @param SMWQueryResult $res
	 * @param $outputmode
	 * 
	 * @return string
	 */	
	protected function getIcalLink( SMWQueryResult $res, $outputmode ) {
		if ( $this->getSearchLabel( $outputmode ) ) {
			$label = $this->getSearchLabel( $outputmode );
		} else {
			$label = wfMessage( 'srf_icalendar_link' )->inContentLanguage()->text();
		}
		
		$link = $res->getQueryLink( $label );
		$link->setParameter( 'icalendar', 'format' );
		
		if ( $this->m_title !== '' ) {
			$link->setParameter( $this->m_title, 'title' );
		}
		
		if ( $this->m_description !== '' ) {
			$link->setParameter( $this->m_description, 'description' );
		}
		
		if ( array_key_exists( 'limit', $this->params ) ) {
			$link->setParameter( $this->params['limit'], 'limit' );
		} else { // use a reasonable default limit
			$link->setParameter( 20, 'limit' );
		}

		// yes, our code can be viewed as HTML if requested, no more parsing needed
		$this->isHTML = ( $outputmode == SMW_OUTPUT_HTML ); 
		return $link->getText( $outputmode, $this->mLinker );
	}
	
	/**
	 * Returns the iCal for a single item.
	 * 
	 * @since 1.5.2
	 * 
	 * @param array $row
	 * 
	 * @return string
	 */
	protected function getIcalForItem( array $row ) {
		$result = '';
		
		$wikipage = $row[0]->getResultSubject(); // get the object
		$wikipage = SMWDataValueFactory::newDataItemValue( $wikipage, null );

		$params = [
			'summary' => $wikipage->getShortWikiText()
		];
		
		$from = null;
		$to = null;
		foreach ( $row as /* SMWResultArray */ $field ) {
			// later we may add more things like a generic
			// mechanism to add whatever you want :)
			// could include funny things like geo, description etc. though
			$req = $field->getPrintRequest();
			$label = strtolower( $req->getLabel() );
			
			switch ( $label ) {
				case 'start': case 'end':
					if ( $req->getTypeID() == '_dat' ) {
						$dataValue = $field->getNextDataValue();

						if ( $dataValue === false ) {
							unset( $params[$label] );
						}
						else {
							$params[$label] = $this->parsedate( $dataValue, $label == 'end' );
							
							$timestamp = strtotime( $params[$label] );
							if ( $from === null || $timestamp < $from )
								$from = $timestamp;
							if ( $to === null || $timestamp > $to )
								$to = $timestamp;
						}
					}
					break;
				case 'location': case 'description': case 'summary':
					$value = $field->getNextDataValue();
					if ( $value !== false ) {
						$params[$label] = $value->getShortWikiText();
					}
					break;
			}
		}
		
		$this->m_timezones->updateRange( $from, $to );
		
		$title = $wikipage->getTitle();
		$article = new Article( $title );
		$url = $title->getFullURL();
		
		$result .= "BEGIN:VEVENT\r\n";
		$result .= "SUMMARY:" . $this->escapeICalendarText( $params['summary'] ) . "\r\n";
		$result .= "URL:$url\r\n";
		$result .= "UID:$url\r\n";
		
		if ( array_key_exists( 'start', $params ) ) $result .= "DTSTART:" . $params['start'] . "\r\n";
		if ( array_key_exists( 'end', $params ) )   $result .= "DTEND:" . $params['end'] . "\r\n";
		if ( array_key_exists( 'location', $params ) ) {
			$result .= "LOCATION:" . $this->escapeICalendarText( $params['location'] ) . "\r\n";
		}
		if ( array_key_exists( 'description', $params ) ) {
			$result .= "DESCRIPTION:" . $this->escapeICalendarText( $params['description'] ) . "\r\n";
		}
		
		$t = strtotime( str_replace( 'T', ' ', $article->getTimestamp() ) );
		$result .= "DTSTAMP:" . date( "Ymd", $t ) . "T" . date( "His", $t ) . "\r\n";
		$result .= "SEQUENCE:" . $title->getLatestRevID() . "\r\n";
		$result .= "END:VEVENT\r\n";
		
		return $result;
	}

	/**
	 * Extract a date string formatted for iCalendar from a SMWTimeValue object.
	 */
	static private function parsedate( SMWTimeValue $dv, $isend = false ) {
		$year = $dv->getYear();
		if ( ( $year > 9999 ) || ( $year < -9998 ) ) return ''; // ISO range is limited to four digits
		
		$year = number_format( $year, 0, '.', '' );
		$time = str_replace( ':', '', $dv->getTimeString( false ) );
		
		if ( ( $time == false ) && ( $isend ) ) { // increment by one day, compute date to cover leap years etc.
			$dv = SMWDataValueFactory::newTypeIDValue( '_dat', $dv->getWikiValue() . 'T00:00:00-24:00' );
		}
		
		$month = $dv->getMonth();
		if ( strlen( $month ) == 1 ) $month = '0' . $month;
		
		$day = $dv->getDay();
		if ( strlen( $day ) == 1 ) $day = '0' . $day;
		
		$result = $year . $month . $day;
		
		if ( $time != false ) $result .= "T$time";
		
		return $result;
	}
	
	/**
	 * Implements esaping of special characters for iCalendar properties of type TEXT. This is defined
	 * in RFC2445 Section 4.3.11.
	 */
	static private function escapeICalendarText( $text ) {
		// Note that \\ is a PHP escaped single \ here
		return str_replace( [ "\\", "\n", ";", "," ], [ "\\\\", "\\n", "\\;", "\\," ], $text );
	}

	/**
	 * @see SMWResultPrinter::getParamDefinitions
	 *
	 * @since 1.8
	 *
	 * @param $definitions array of IParamDefinition
	 *
	 * @return array of IParamDefinition|array
	 */
	public function getParamDefinitions( array $definitions ) {
		$params = parent::getParamDefinitions( $definitions );

		$params['title'] = [
			'default' => '',
			'message' => 'srf_paramdesc_icalendartitle',
		];

		$params['description'] = [
			'default' => '',
			'message' => 'srf_paramdesc_icalendardescription',
		];

		return $params;
	}

}
