<?php

/**
 * Extension for Contao 4
 *
 * @copyright  Softleister 2014-2021
 * @author     Softleister <info@softleister.de>
 * @package    contao-pdfforms-bundle
 * @licence    LGPL
 * @see        https://github.com/do-while/contao-pdfforms-bundle
 */


namespace Softleister\Pdfforms;

require_once( 'PdfformsHelper.php' );

//-----------------------------------------------------------------
//  HookControl-Klasse
//-----------------------------------------------------------------
class PdfformsHookControl extends \Contao\Backend
{
    //-----------------------------------------------------------------
    //  myPrepareFormData:    Formulardaten vorverarbeiten
    //-----------------------------------------------------------------
    public function myPrepareFormData( &$arrSubmitted, $arrLabels, $arrFields, $objForm )
    {
        if( $objForm->pdff_on != '1' ) return;              // PDF-Forms abgeschaltet!

        // Aufbau eines Feldes mit den Feldtypen
        $db = \Contao\Database::getInstance();
        $objFields = $db->prepare("SELECT name, type FROM tl_form_field WHERE invisible<>1 AND pid=?")
                        ->execute($objForm->id);

        $arrFields = array();
        $arrTypes  = array();
        while( $objFields->next() ) {
            if( !empty($objFields->name) ) {
                $arrTypes[$objFields->name] = $objFields->type;
            }
        }
        foreach( $arrTypes as $key=>$type ) {
            $widgetName = PdfformsHelper::normalisierung($key);         // normalisierter Feldname

            $arrFields[$widgetName]['type']  = $type;                   // Feldtyp (wichtig für die auswertenden InsertTags)
            $arrFields[$widgetName]['value'] = $arrSubmitted[$key];     // gesendeter Wert
            $arrFields[$widgetName]['orig']  = $key;                    // Original Feldname
        }

        $filename = \Contao\StringUtil::standardize(\Contao\StringUtil::restoreBasicEntities($objForm->title)) . $this->replaceInsertTags($objForm->pdff_fileext, false);
        $savepath = \Contao\FilesModel::findByUuid($objForm->pdff_savepath)->path;
        if( file_exists(TL_ROOT . '/' . $savepath . '/' . $filename . '.pdf') ) {
            $i = 2;
            while( file_exists(TL_ROOT . '/' . $savepath . '/' . $filename . '-' . $i . '.pdf') ) $i++;
            $filename = $filename . '-' . $i;
        }
        $pdfdatei = $savepath . '/' . $filename . '.pdf';
        file_put_contents(TL_ROOT . '/' . $pdfdatei, '');               // leere Datei erzeugen um Dateinamen zu sichern

        $arrPDF = array( 'formid'        => $objForm->id,
                         'formtitle'     => $objForm->title,
                         'filename'      => $filename,
                         'vorlage'       => \Contao\FilesModel::findByUuid($objForm->pdff_vorlage)->path,
                         'handler'       => $objForm->pdff_handler,
                         'savepath'      => $savepath,
                         'protect'       => $objForm->pdff_protect,
                         'openpassword'  => \Contao\Controller::replaceInsertTags( PdfformsHelper::decrypt($objForm->pdff_openpassword) ),
                         'protectflags'  => \Contao\StringUtil::deserialize($objForm->pdff_protectflags),
                         'password'      => \Contao\Controller::replaceInsertTags( PdfformsHelper::decrypt($objForm->pdff_password) ),
                         'multiform'     => \Contao\StringUtil::deserialize($objForm->pdff_multiform),
                         'allpages'      => $objForm->pdff_allpages,
                         'offset'        => array(0, 0),
                         'textcolor'     => $objForm->pdff_textcolor,
                         'title'         => $objForm->pdff_title,
                         'author'        => $objForm->pdff_author,
                         'R'             => \Contao\FilesModel::findByUuid($objForm->pdff_font)->path,
                         'B'             => \Contao\FilesModel::findByUuid($objForm->pdff_fontb)->path,
                         'I'             => \Contao\FilesModel::findByUuid($objForm->pdff_fonti)->path,
                         'IB'            => \Contao\FilesModel::findByUuid($objForm->pdff_fontbi)->path,
                         'arrFields'     => $arrFields,
                       );
        unset( $arrFields );
        if( !is_array($arrPDF['protectflags']) ) $arrPDF['protectflags'] = array( $arrPDF['protectflags'] );

        // Offsets eintragen, wenn angegeben
        $ofs = \Contao\StringUtil::deserialize($objForm->pdff_offset);
        if( isset($ofs[0]) && is_numeric($ofs[0]) ) $arrPDF['offset'][0] = $ofs[0];
        if( isset($ofs[1]) && is_numeric($ofs[1]) ) $arrPDF['offset'][1] = $ofs[1];

        // HOOK: before pdf generation
        if( isset($GLOBALS['TL_HOOKS']['pdf_formsBeforePdf']) && \is_array($GLOBALS['TL_HOOKS']['pdf_formsBeforePdf']) ) {
            foreach( $GLOBALS['TL_HOOKS']['pdf_formsBeforePdf'] as $callback ) {
                $arrPDF = \Contao\System::importStatic($callback[0])->{$callback[1]}( $arrPDF, $this );
            }
        }


        //-- Include Settings
        $tcpdfinit = \Contao\Config::get("pdftemplateTcpdf");

        // 1: Own settings addressed via app/config/config.yml
        if( !empty($tcpdfinit) && file_exists(TL_ROOT . '/' . $tcpdfinit) ) {
            require_once(TL_ROOT . '/' . $tcpdfinit);
        }
        // 2: Own tcpdf.php from files directory
        else if( file_exists(TL_ROOT . '/files/tcpdf.php') ) {
            require_once(TL_ROOT . '/files/tcpdf.php');
        }
        // 3: From config directory (up to Contao 4.6)
        else if( file_exists(TL_ROOT . '/vendor/contao/core-bundle/src/Resources/contao/config/tcpdf.php') ) {
            require_once(TL_ROOT . '/vendor/contao/core-bundle/src/Resources/contao/config/tcpdf.php');
        }
        // 4: From config directory of tcpdf-bundle (from Contao 4.7)
        else if( file_exists(TL_ROOT . '/vendor/contao/tcpdf-bundle/src/Resources/contao/config/tcpdf.php') ) {
            require_once(TL_ROOT . '/vendor/contao/tcpdf-bundle/src/Resources/contao/config/tcpdf.php');
        }
        // 5: not found? - Then take it from this extension
        else {
            require_once(TL_ROOT . '/vendor/do-while/contao-pdfforms-bundle/src/Resources/contao/config/tcpdf.php');
        }


        //--- PDF-Datei erstellen und speichern ---
        if( PdfformsHelper::pdfforms( 'S', $arrPDF, $pdfdatei ) ) {

            //--- PDF-Datei in der Dateiverwaltung eintragen ---
            $objFile = \Contao\Dbafs::addResource( $pdfdatei );                // Datei in der Dateiverwaltung eintragen
            \Contao\Dbafs::updateFolderHashes( $strUploadFolder );

            //--- PDF-Datei wenn gefordert als E-Mail-Anhang bereitstellen ---
            if( $arrPDF['handler'] === 'email' ) {                      //=== Datei an die E-Mail anhängen? ===
                $_SESSION['FILES']['pdfattachment'] = array (
                    'name'      =>  $arrPDF['filename'] . '.pdf',
                    'type'      =>  'application/x-pdf',
                    'tmp_name'  =>  TL_ROOT . '/' . $pdfdatei,
                    'error'     =>  0,
                    'size'      =>  filesize( TL_ROOT . '/' . $pdfdatei ),
                    'uploaded'  =>  false,
                    'uuid'      =>  \Contao\StringUtil::binToUuid( $objFile->uuid )
                );
            }
        }
        else {
            $pdfdatei = '';        // es wurde keine Datei erzeugt
        }

        if( class_exists('\NotificationCenter\Model\Notification') ) {
            //=== Benachrichtigungen senden ===
            $arrTokens = array();
            $arrTokens['admin_email'] = $GLOBALS['TL_ADMIN_EMAIL'];
            if( !empty($pdfdatei) ) $arrTokens['pdfdocument'] = $pdfdatei;
            $arrTokens['openpassword'] = $arrPDF['openpassword'];
            $arrTokens['raw_data'] = '';

            foreach( $arrSubmitted as $key=>$val ) {
                $arrTokens['form_'.$key] = $val;
                $arrTokens['raw_data'] .= (isset($arrLabels[$key]) ? $arrLabels[$key] : ucfirst($key)) . ': ' . (is_array($val) ? implode(', ', $val) : $val) . "\n";
            }
            if( is_array($_SESSION['FILES']) ) {
                foreach( $_SESSION['FILES'] as $key=>$val ) {
                    if( $key === 'pdfattachment' ) continue;
    
                    $arrTokens['form_'.$key] = \NotificationCenter\Util\Form::getFileUploadPathForToken( $val );
                    $arrTokens['raw_data'] .= (isset($arrLabels[$key]) ? $arrLabels[$key] : ucfirst($key)) . ': ' . $val['name'] . "\n";
                }
            }

            if( $objForm->pdff_notification > 0 ) {             // IF() notification defined )
                $objNotification = \NotificationCenter\Model\Notification::findByPk( $objForm->pdff_notification );
                if (null !== $objNotification) {
                    $objNotification->send( $arrTokens, $GLOBALS['TL_LANGUAGE'] );
                }
                else {
                    \Contao\System::log('No notification "pdf_form_transmit" found!', __METHOD__, TL_FORMS);
                    return;
                }
            }
        }

        // HOOK: after pdf generation
        if( isset($GLOBALS['TL_HOOKS']['pdf_formsAfterPdf']) && \is_array($GLOBALS['TL_HOOKS']['pdf_formsAfterPdf']) ) {
            foreach( $GLOBALS['TL_HOOKS']['pdf_formsAfterPdf'] as $callback ) {
                \Contao\System::importStatic($callback[0])->{$callback[1]}( $pdfdatei, $arrPDF, $this );
            }
        }

    }


    //-----------------------------------------------------------------
    //  InsertTags abarbeiten
    //
    //  {{pdf_forms::pdfdocument}}
    //  {{pdf_forms::pdfdocument::name}}
    //  {{pdf_forms::password_random}}
    //-----------------------------------------------------------------
    public function myReplaceInsertTags( $strTag )
    {
        $tag = explode( '::', $strTag );
        if( $tag[0] !== 'pdf_forms' ) return false;                             // nicht zuständig für diese InsertTags

        if( strtolower($tag[1] === 'pdfdocument' ) ) {
            if( !isset($_SESSION['pdf_forms']['pdfdocument']) ) return false;   // bisher kein Dokument erstellt

            if( !isset($tag[2]) ) $tag[2] = '';                                 // kein 3. Parameter angegeben, mit default ergänzen
            switch( $tag[2] ) {
                case 'name':    return basename($_SESSION['pdf_forms']['pdfdocument']);
                default:        return $_SESSION['pdf_forms']['pdfdocument'];
            }
        }
        else if( strtolower($tag[1] === 'password_random' ) ) {
            return PdfformsHelper::getRandomPassword( );
        }

        return false;                                                           // kein bekannter InsertTag => nicht zuständig!
    }


    //-----------------------------------------------------------------
}
