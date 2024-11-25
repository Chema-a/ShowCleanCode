<?php

namespace Seides_ERP\Http\Controllers;


use Carbon\Carbon;

use Seides_ERP\Models\CONFIG;
use Seides_ERP\Models\ERP_ProveedoresMovs as ProveedorFac;
use Illuminate\Http\Request;
use Seides_ERP\Models\CXC_Cliente_Fisico as Cliente_Fisico;
use Seides_ERP\Models\CXC_Cliente_Moral as Cliente_Moral;
use Seides_ERP\Models\CXC_Cliente_Movimiento_Factura as Movimiento_Factura;
use Seides_ERP\Models\CXC_Cliente_Movimiento_Bitacora as Movimiento_Bitacora;
use Seides_ERP\Models\CXC_Cliente_Movimiento as Cliente_Movimiento;

use Seides_ERP\Http\Requests\CuentasCobrar_VerificaSAT\VerificaSATRequest;


class CuentasCobrar_VerificaSAT extends Controller
{
    private function _array($array)
    {
        echo "<pre>";
        print_r($array);
        echo "</pre>";
        exit();
    }

    public function verificaSAT(VerificaSATRequest $request , Cliente_Movimiento $movimiento = null){
        $cliente = ($request->tipo_cliente == Cliente_Fisico::PERSONA_FISICA) ? Cliente_Fisico::find($request->id_cliente) : Cliente_Moral::find($request->id_cliente) ;
        $estatus = '';
        $tipoDocumento = 'Factura';
        $comprobante = 'I';
        $moneda_base = $cliente->datos->moneda;
        $totalPorFacturar = $request->monto_factura;
        if ($movimiento == null) {
            $totalFacturado =  $request->total_orden;
        } else {
            $totalFacturado =  $request->total_faltante;
        }

        $rfcEmisor = trim($request->rfcEmisor);
        $diasCredito = $cliente->cobranza->dias_credito->plazo_dias;
        $current = Carbon::now()->format('Y-m-d');

        $pdf = $request->file('pdf_file');
        $xml = $request->file('xml_file');

        $ext_pdf = $pdf->getClientOriginalExtension();
        $ext_xml = $xml->getClientOriginalExtension();

        $temp_xml =  'temp_' . '_' . $comprobante  . '.' . $ext_xml;
        $temp_pdf =  'temp_' . '_' . $comprobante  . '.' . $ext_pdf;

        \Storage::disk('FactTemporal')->put($temp_xml,  \File::get($xml));
        \Storage::disk('FactTemporal')->put($temp_pdf,  \File::get($pdf));

        $resultado = $this->SAT($temp_xml, $temp_pdf, $totalPorFacturar, $totalFacturado,$tipoDocumento, $moneda_base ,$diasCredito, $rfcEmisor);

        if($resultado['codigo'] == 400){
            if($movimiento != null){
                $movimiento->guardar_error_bitacora($resultado['data']['uuid'], $resultado['data']['totalXML'], 'No Pre-Procesado',$resultado['mensaje'] );
            }
            return response()->json(['errors' => ['error' => $resultado['mensaje']]], 400);
        }
        if($movimiento != null){
            $movimiento->guardar_error_bitacora($resultado['data']['uuid'], $resultado['data']['totalXML'], 'Pre-procesado Correctamente','Pre-procesado Correctamente' );
        }
        return response()->json(['factura' => $resultado['data'] ], 200);
    }
    private function SAT($archivo, $archivo2, $totalPorFacturar, $totalFacturado, $tipoDocumento, $moneda_base, $diasCredito, $rfcEmisor)
    {
        $resultado = [
            'codigo' => 200,
            'mensaje' => 'Exito',
            'data' => 1,
            'status' => ''
        ];
        libxml_use_internal_errors(true);
        $load = simplexml_load_file("Facturacion/TEMPORAL/" . $archivo);
        $ns = $load->getNamespaces(true);

        $comprobanteData = $this->processComprobanteData($load, $ns, $totalPorFacturar,$moneda_base, $resultado);


        if(!$this->isMonedaValid($moneda_base, $comprobanteData['moneda'],$resultado)
        or !$this->isTotalXMLValid($comprobanteData['totalXML'], $totalPorFacturar,$totalFacturado, $resultado)){
            return $resultado;
        }
        $fechaVencimiento = $this->fechaVencimiento($diasCredito);

        $dom = $this->loadDOMDocument($archivo);

        $uuid = $this->getUUIDFromDOM($dom);

        $resultado['data'] = $this->createCollection($comprobanteData,$totalFacturado ,$totalPorFacturar, $uuid, $fechaVencimiento, $archivo, $archivo2);

        if($this->isUUIDinDatabase($uuid, $resultado)){
            return $resultado;
        }

        if ($this->validateDocumentType($tipoDocumento, $comprobanteData['tipo_comprobante'])) {
            $this->processDocumentValidation($uuid, $comprobanteData['emisorRFC'], $comprobanteData['receptorRFC'], $comprobanteData['total_comprobante'], $comprobanteData['resultado']);
        } else {
            $this->setInvalidDocumentStatus($resultado);
        }
        return $resultado;
    }


    private function processComprobanteData($load, $ns, &$totalPorFacturar,$moneda_base, &$resultado)
    {

        foreach ($load->xpath('//cfdi:Comprobante') as $cfdiComprobante){                                      //header del cfdi
            $total_comprobante       = $cfdiComprobante['Total'];                                                       //campo del XML
            $tipo_comprobante        = (string)$cfdiComprobante['TipoDeComprobante'];                                   //campo del XML
            $serie                   = (string)$cfdiComprobante['Serie'];                                               //campo del XML
            $folio                   = (string)$cfdiComprobante['Folio'];                                               //campo del XML
            $fecha                   = (string)$cfdiComprobante['Fecha'];                                               //campo del XML
            $formaPago               = (string)$cfdiComprobante['FormaPago'];                                           //campo del XML
            $metodoPago              = (string)$cfdiComprobante['MetodoPago'];                                          //campo del XML
            $moneda                  = ((string)$cfdiComprobante['Moneda'] == 'MXN') ? 'MN' : (string)$cfdiComprobante['Moneda'];                                             //campo del XML
        }
        foreach ($load->xpath('//cfdi:Concepto') as $cfdiConcepto){                                            //header del cfdi
            $descripcionXML = (string)$cfdiConcepto['Descripcion'];                                                     //campo del XML
        }
        if(!$this->isMonedaValid($moneda_base, $moneda, $resultado))
            return ;

        $totalXML = json_decode($total_comprobante);
        $totalXML = str_replace(['$', ','], ['', ''], $totalXML);

        $child = $load->children($ns['cfdi']);                                                                         //namespace cfdi del xml
        $emisorRFC = (string) $child->Emisor->attributes()->Rfc;
        $emisorNombre = (string) $child->Emisor->attributes()->Nombre;
        $receptorRFC = (string) $child->Receptor->attributes()->Rfc;
        $receptorNombre = (string) $child->Receptor->attributes()->Nombre;

        return [
            'formaPago' => $formaPago,
            'total_comprobante' => $total_comprobante,
            'tipo_comprobante' => $tipo_comprobante,
            'serie' => $serie,
            'folio' => $folio,
            'fecha' => $fecha,
            'metodoPago' => $metodoPago,
            'moneda' => $moneda,
            'descripcionXML' => $descripcionXML,
            'totalXML' => $totalXML,
            'emisorRFC' => $emisorRFC,
            'emisorNombre' => $emisorNombre,
            'receptorRFC' => $receptorRFC,
            'receptorNombre' => $receptorNombre
        ];
    }

    private function loadDOMDocument($archivo)
    {
        $dom = new \DOMDocument('1.0', 'utf-8');
        $dom->load("Facturacion/TEMPORAL/" . $archivo);
        return $dom;
    }

    private function getUUIDFromDOM($dom)
    {
        $uuid = null;
        foreach ($dom->getElementsByTagNameNS('http://www.sat.gob.mx/TimbreFiscalDigital', '*') as $elemento) {
            $uuid = $elemento->getAttribute('UUID');
        }
        return $uuid;
    }

    private function isMonedaValid($moneda_base,$moneda ,&$resultado){
        if ($moneda_base != $moneda) {
            $resultado['mensaje'] = 'La Moneda del Comprobante No Concuerda con la del Proveedor';
            $resultado['codigo'] = 400;
            return false;
        }
        return true;
    }
    private function isTotalXMLValid($totalXML , $totalPorFacturar, $totalRecibido ,&$resultado)
    {
        if( $totalXML >= floatval($totalRecibido)){
            $resultado['codigo'] = 400;
            $resultado['mensaje'] = 'La Cantidad Total del XML es Mayor a la Cantidad Total';
            return false;
        }
        return true;
    }

    private function createCollection($comprobanteData, $totalFacturado, $totalPorFacturar, $uuid, $fechaVencimiento, $archivo, $archivo2)
    {
        return collect([
            'tipo_comprobante'       => $comprobanteData['tipo_comprobante'],
            'totalXML'               => number_format($comprobanteData['totalXML'], 2, '.', ','),
            'totalFacturado'         => number_format(floatval($totalFacturado) + floatval($comprobanteData['totalXML']), 2, '.', ','),
            'totalFacturadoAnterior' => $totalFacturado,
            'totalPorFacturar'       => number_format(floatval($totalPorFacturar) - (floatval($comprobanteData['totalXML'])), 2, '.', ','),
            'serie'                  => $comprobanteData['serie'],
            'folio'                  => $comprobanteData['folio'],
            'fecha'                  => Carbon::parse($comprobanteData['fecha'])->format('Y-m-d'),
            'moneda'                 => $comprobanteData['moneda'],
            'forma_pago'             => $comprobanteData['formaPago'],
            'metodo_pago'            => $comprobanteData['metodoPago'],
            'emisor_RFC'             => $comprobanteData['emisorRFC'],
            'emisor_Nombre'          => $comprobanteData['emisorNombre'],
            'receptor_RFC'           => $comprobanteData['receptorRFC'],
            'receptor_Nombre'        => $comprobanteData['receptorNombre'],
            'descripcionXML'         => $comprobanteData['descripcionXML'],
            'uuid'                   => $uuid,
            'fechaVencimiento'       => $fechaVencimiento,
            'archivo'                => $archivo,
            'archivo2'               => $archivo2
        ]);
    }

    private function processDocumentValidation($uuid, $emisorRFC, $receptorRFC, $total_comprobante, &$resultado)
    {
        $validacionSAT = CONFIG::where('id', 16)->first();
        if ($validacionSAT['activo'] != 1) {
            return;
        }
            $info = json_decode($this->verificarComprobante($emisorRFC, $receptorRFC, $total_comprobante, $uuid));
            if ($this->isCanceledWithAcceptance($info) and $info->Estado == 'Cancelado') {
                $resultado['mensaje'] = 'El documento está cancelado. No se puede subir.';
                $resultado['codigo'] = 400;
            } elseif ($this->isCanceledWithAcceptance($info)) {
                $resultado['mensaje'] = 'El documento ha iniciado un proceso de cancelación. No se puede subir';
                $resultado['codigo'] = 400;
            }
            if ($this->isSuccessfullyObtained($info)) {
                $resultado['mensaje'] = 'El Tipo de Documento NO Concuerda con Concuerda con la Accion del Sistema (FACTURA O NOTA DE CREDITO)';
                $resultado['codigo'] = 400;
            } else {
                $resultado['mensaje'] = 'El Comprobante NO Existe en el Registro del SAT';
                $resultado['codigo'] = 400;
            }

    }

    private function validateDocumentType($tipoDocumento, $tipoComprobante)
    {
        return ($tipoDocumento == 'Factura' and $tipoComprobante == 'I') or ($tipoDocumento == 'Nota' and $tipoComprobante == 'E');
    }

    private function isCanceledWithAcceptance($info)
    {
        return $info->EstatusCancelacion == 'Cancelado con aceptación' or $info->EstatusCancelacion == 'Cancelado sin aceptación';
    }

    private function isSuccessfullyObtained($info)
    {
        return $info->CodigoEstatus == 'S - Comprobante obtenido satisfactoriamente.';
    }

    private function setInvalidDocumentStatus(&$resultado)
    {
        $resultado['mensaje'] = 'El documento se quiso registrar en un formato inválido';
        $resultado['codigo'] = 400;

    }

    private function validarDiasCredito($diasCredito, $fecha)
    {
        $fechaActual        = Carbon::now();
        $fechaOC            = Carbon::parse($fecha);
        $diferencia         = $fechaActual->diffInDays($fechaOC);
        return $diferencia <= $diasCredito;
    }
    private function fechaVencimiento($diasCredito)
    {
        $fechaActual = Carbon::now();
        $fechaVencimiento = $fechaActual->addDays($diasCredito);
        return $fechaVencimiento->format('Y-m-d');
    }
    private function isUUIDinDatabase($uuid, &$resultado)
    {
        if (Movimiento_Factura::where('uuid',$uuid)->exists()) {
            $resultado['mensaje'] = 'El UUID del XML Ya Fue Registrado Previamente';
            $resultado['codigo'] = 400;
            return false;
        }
        return true;

    }

    public function verificarComprobante($emisorRFC,$receptorRFC,$total_comprobante,$uuid){
        $soap = sprintf('<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/"><soapenv:Header/><soapenv:Body><tem:Consulta><tem:expresionImpresa>?re=%s&amp;rr=%s&amp;tt=%s&amp;id=%s</tem:expresionImpresa></tem:Consulta></soapenv:Body></soapenv:Envelope>', $emisorRFC,$receptorRFC,$total_comprobante,$uuid);

        $headers = [
            'Content-Type: text/xml;charset=utf-8',
            'SOAPAction: http://tempuri.org/IConsultaCFDIService/Consulta',
            'Content-length: '.strlen($soap)
        ];

        $url = 'https://consultaqr.facturaelectronica.sat.gob.mx/ConsultaCFDIService.svc';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $soap);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $res = curl_exec($ch);
        curl_close($ch);
        $xml = simplexml_load_string($res);
        $data = $xml->children('s', true)->children('', true)->children('', true);
        $data = json_encode($data->children('a', true), JSON_UNESCAPED_UNICODE);
        return $data;
    }
}
