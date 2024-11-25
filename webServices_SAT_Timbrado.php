<?php

namespace Seides_ERP\Http\Controllers;


use SoapClient;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Response;
use PhpCfdi\Credentials\Credential;

class webServices_SAT_Timbrado extends Controller
{
    // FUNCION PRINCIPAL
    public function timbrarFactura(Request $request){
        $fechaEmision = Carbon::now()->format('Y-m-d\TH:i:s');
        $token = $this->autenticacionTest();
        $creator = $this->generarCFDI($request, $fechaEmision);
        $comprobante = $this->sellarCFDI($creator);
        // método de ayuda para validar usando las validaciones estándar de creación de la librería
        $asserts = $comprobante->validate();
        $asserts_list = $this->validateComprobante($asserts);
        $xml = $comprobante->asXml();
        $comprobanteXML = $this->timbrarXMLSAT($xml, $token->AutenticarBasicoResult);
        $base64_dataPDF = $this->get64base_dataPDF($comprobanteXML->TimbrarXMLV2Result,$token->AutenticarBasicoResult);
        $this->generarPDF($base64_dataPDF);
        return response()->json(['asserts'=>$asserts_list, 'xml' => $comprobanteXML->TimbrarXMLV2Result]  , 200);
        // dd( $cadena_original);
    }

    private function autenticacionTest()
    {
        try {
            // URL del servicio WSDL para autenticación de Digibox
            $wsdlUrlAuthentication = 'http://testtimbrado.digibox.com.mx/Autenticacion/wsAutenticacion.asmx?WSDL';
            $usuario = "demo2";
            $contraseña = "123456789";

            // Configurar el cliente SOAP
            $soapClient = new SoapClient($wsdlUrlAuthentication, [
                'trace' => true, // Habilitar trazas para depuración
            ]);

            // Datos Usuario y Password
            $data = [
                'usuario' => $usuario,
                'password' => $contraseña
            ];

            // Llamar al método AutenticarBasico para que genere el Token de acceso
            return $soapClient->AutenticarBasico($data);

        } catch (\SoapFault $fault) {
            // Manejar errores de SOAP
            return response()->json(['errors' => ['error' => $fault->faultstring]], 400)->throwResponse();
        }


    }
    private function generarCFDI($request, $fechaEmision)
    {
        $ruta_certificado = 'certificados_sat/CSD_Sucursal_1_EKU9003173C9_20230517_223850.cer';
        $certificado = new \CfdiUtils\Certificado\Certificado($ruta_certificado);

        // Informacion de Atributos principales
        $comprobanteAtributos = [
            'Version' => '4.0',
            'Serie' => 'A',
            'Folio' => '0000123456',
            'Fecha'=> $fechaEmision,
            'FormaPago' => '99',
            'SubTotal' => $request->total_orden - $request->iva_orden,
            'Total' => $request->total_orden,
            'TipoDeComprobante' => 'I',
            'Moneda' => 'MXN',
            'LugarExpedicion' => '06370',
            'MetodoPago' => 'PPD',
            'Exportacion'=>'01'
        ];
        $creator = new \CfdiUtils\CfdiCreator40($comprobanteAtributos, $certificado);

        $comprobante = $creator->comprobante();
        // Informacion de Emisor
        $comprobante->addEmisor([
            'Nombre' => 'ESCUELA KEMPER URGATE',
            'RegimenFiscal' => '601',
        ]);
        // Informacion de Receptor
        $comprobante->addReceptor([
            'Rfc' => 'MOFY900516NL1',
            'Nombre' => 'YADIRA MAGALY MONTAÑEZ FELIX',
            'DomicilioFiscalReceptor' => '91779',
            'RegimenFiscalReceptor' => '612',
            'UsoCFDI' => 'G01'
        ]);
        // Informacion de conceptos
        $this->addConceptos($comprobante, $request);
        // método de ayuda para establecer las sumas del comprobante e impuestos
        // con base en la suma de los conceptos y la agrupación de sus impuestos
        $creator->addSumasConceptos(null, 2);
        // dd($creator);
        return $creator;
    }
    private function addConceptos(&$comprobante, $request){
        // Itera sobre los Insumos recibidios para añadirlos como Concepto
        foreach ($request->insumo as $index => $insumo) {
            $clave_prod = $certificado = preg_replace("/\s+/", '',$request->codigo_insumo[$index]);
            $iva = $request->tasa_iva[$index]/100 ;
            $comprobante->addConcepto([
                'ClaveProdServ' => '27111500',
                'Cantidad' => $request->cantidad[$index],
                'NoIdentificacion' => $clave_prod,
                'Descripcion' => $request->descripcion_insumo[$index],
                'ValorUnitario' => $request->precio[$index],
                'Importe' => $request->monto[$index],
                'ClaveUnidad' => '11',
                'Unidad' => $request->unidad[$index],
                'ObjetoImp' => '02'
            ])->addTraslado([
                'Base'=> $request->monto[$index],
                'Impuesto' => '002',
                'TipoFactor' => 'Tasa',
                'TasaOCuota' => (string)$iva.'0000',
                'Importe' => $request->monto_iva[$index]
            ]);
        }
    }
    private function sellarCFDI($creator){
        $certificado = 'certificados_sat/CSD_Sucursal_1_EKU9003173C9_20230517_223850.cer';
        $key = 'certificados_sat/CSD_Sucursal_1_EKU9003173C9_20230517_223850.key';
        $password = '12345678a';
        $csd = Credential::openFiles($certificado, $key, $password);

        // método de ayuda para generar el sello (obtener la cadena de origen y firmar con la llave privada)
        $creator->addSello($csd->privateKey()->pem(), $csd->privateKey()->passPhrase());

        // método de ayuda para mover las declaraciones de espacios de nombre al nodo raíz
        $creator->moveSatDefinitionsToComprobante();



        // método de ayuda para generar el xml y retornarlo como un string
        $creator->asXml();
        return $creator;
    }
    private function validateComprobante($asserts){
        $asserts_list = [];
        $errors = [];
        foreach ($asserts as $assert) {
            if($assert->getStatus() == 'ERROR'){
                array_push($errors ,'Status:'.$assert->getStatus().': '. $assert->getTitle());

            }
            // dd($assert);
            else{
                array_push($asserts_list ,'Status:'.$assert->getStatus().': '. $assert->getTitle());
            }

        }
        if(count($errors)> 0){
            return response()->json(['errors' => $errors], 400)->throwResponse();

        }
        return $asserts_list;

    }
    private function timbrarXMLSAT($xml, $token)
    {
        try {
            // URL del servicio WSDL para autenticación de Digibox
            $wsdlUrlTimbrado = 'http://testtimbrado.digibox.com.mx/Timbrado/wsTimbrado.asmx?WSDL';
            // Configurar el cliente SOAP
            $soapClient = new SoapClient($wsdlUrlTimbrado, [
                'trace' => true, // Habilitar trazas para depuración
            ]);

            // Datos de Comprobante y Token de Autenticación
            $data = [
                'xmlComprobante' => $xml,
                'tokenAutenticacion' => $token,
                'personalizado' => 1
            ];

            // Llamar al método TimbrarXML para realizar el timbrado con
            $results = $soapClient->TimbrarXMLV2($data);
            return $results;

        } catch (\SoapFault $fault) {
            // Manejar errores de SOAP
            return response()->json(['errors' => ['error' => $fault->faultstring]], 400)->throwResponse();
        }
    }
    private function get64base_dataPDF($xml, $token){
        try {
            // URL del servicio WSDL para autenticación de Digibox
            $wsdlUrlTimbrado = 'http://digibox2t.cloudapp.net/ServiciosAdicionales/FacturaPdf.asmx?WSDL';
            // Configurar el cliente SOAP
            $soapClient = new SoapClient($wsdlUrlTimbrado, [
                'trace' => true, // Habilitar trazas para depuración
            ]);
            // Datos de Comprobante y Token de Autenticación
            $data = [
                'comprobanteXml' => $xml,
                'token' => $token,
                'personalizado' => TRUE
            ];

            // Llamar al método TimbrarXML para realizar el timbrado con
            $results = $soapClient->GenerarBytes($data)->GenerarBytesResult;
            return $results;

        } catch (\SoapFault $fault) {
            // Manejar errores de SOAP
            return response()->json(['errors' => ['error' => $fault->faultstring]], 400)->throwResponse();
        }
    }
    private function generarPDF($base64_dataPDF)
    {
            $pdf_base64 = "base64pdf.txt";
            $myfile = fopen($pdf_base64, "w") or die("Unable to open file!");
            fwrite($myfile, $base64_dataPDF);
            fclose($myfile);
            //Get File content from txt file
            $pdf_base64_handler = fopen($pdf_base64,'r');
            $pdf_content = fread ($pdf_base64_handler,filesize($pdf_base64));
            fclose ($pdf_base64_handler);
            //Decode pdf content
            $pdf_decoded = base64_decode ($pdf_content);
            //Write data back to pdf file
            $pdf = fopen ('clientes_movimientos/factura_test'.'.pdf','w');
            fwrite ($pdf,$pdf_decoded);
            //close output file
            fclose ($pdf);
    }

}

