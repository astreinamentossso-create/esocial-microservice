<?php

namespace App;

use NFePHP\eSocial\Event;
use NFePHP\eSocial\Tools;
use NFePHP\eSocial\Common\Certificate;

class EsocialHandler
{
    private const ENDPOINTS = [
        'restricted_production' => [
            'envio' => 'https://webservices.producaorestrita.esocial.gov.br/servicos/empregador/enviarloteeventos/WsEnviarLoteEventos.svc',
            'consulta' => 'https://webservices.producaorestrita.esocial.gov.br/servicos/empregador/consultarloteeventos/WsConsultarLoteEventos.svc',
        ],
        'production' => [
            'envio' => 'https://webservices.esocial.gov.br/servicos/empregador/enviarloteeventos/WsEnviarLoteEventos.svc',
            'consulta' => 'https://webservices.esocial.gov.br/servicos/empregador/consultarloteeventos/WsConsultarLoteEventos.svc',
        ],
    ];

    /**
     * Submit an eSocial event
     */
    public function submit(
        string $eventType,
        array $eventData,
        string $cnpj,
        string $certificateBase64,
        string $certificatePassword,
        string $environment = 'restricted_production'
    ): array {
        // Decode the certificate from base64
        $certContent = base64_decode($certificateBase64);
        if (!$certContent) {
            throw new \InvalidArgumentException('Invalid certificate: could not decode base64');
        }

        // Save certificate temporarily
        $tempCertPath = tempnam(sys_get_temp_dir(), 'esocial_cert_');
        file_put_contents($tempCertPath, $certContent);

        try {
            // Load certificate using sped-esocial
            $certificate = Certificate::readPfx($certContent, $certificatePassword);

            // Configure the tools
            $config = $this->buildConfig($cnpj, $environment);
            $tools = new Tools($config, $certificate);

            // Build the event XML
            $xml = $this->buildEventXml($eventType, $eventData, $cnpj);

            // Sign the XML
            $signedXml = $tools->signEvent($xml);

            // Send to eSocial
            $response = $tools->sendLot([$signedXml], $this->generateLotId());

            // Parse response
            $result = $this->parseSubmitResponse($response);

            return $result;
        } finally {
            // Clean up temp file
            if (file_exists($tempCertPath)) {
                unlink($tempCertPath);
            }
        }
    }

    /**
     * Check eSocial event status by protocol
     */
    public function checkStatus(
        string $protocol,
        string $cnpj,
        string $certificateBase64,
        string $certificatePassword,
        string $environment = 'restricted_production'
    ): array {
        $certContent = base64_decode($certificateBase64);
        if (!$certContent) {
            throw new \InvalidArgumentException('Invalid certificate: could not decode base64');
        }

        try {
            $certificate = Certificate::readPfx($certContent, $certificatePassword);
            $config = $this->buildConfig($cnpj, $environment);
            $tools = new Tools($config, $certificate);

            $response = $tools->consultLot($protocol);

            return $this->parseStatusResponse($response);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Erro ao consultar status: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Build the sped-esocial config array
     */
    private function buildConfig(string $cnpj, string $environment): string
    {
        $tpAmb = $environment === 'production' ? 1 : 2;
        $cnpjClean = preg_replace('/\D/', '', $cnpj);

        $config = [
            'tpAmb' => $tpAmb,
            'verProc' => 'ALA_SST_1.0',
            'eventoVersion' => 'S_01_02_00',
            'layoutVersion' => 'S_01_02_00',
            'tpInsc' => 1,
            'nrInsc' => substr($cnpjClean, 0, 8),
            'nmRazao' => '',
        ];

        return json_encode($config);
    }

    /**
     * Build event XML based on type
     */
    private function buildEventXml(string $eventType, array $data, string $cnpj): string
    {
        $cnpjClean = preg_replace('/\D/', '', $cnpj);

        switch ($eventType) {
            case 'S-2210':
                return $this->buildS2210($data, $cnpjClean);
            case 'S-2220':
                return $this->buildS2220($data, $cnpjClean);
            case 'S-2240':
                return $this->buildS2240($data, $cnpjClean);
            default:
                throw new \InvalidArgumentException("Tipo de evento não suportado: {$eventType}");
        }
    }

    /**
     * Build S-2210 - CAT
     */
    private function buildS2210(array $data, string $cnpj): string
    {
        $std = new \stdClass();

        // ideEvento
        $std->ideEvento = new \stdClass();
        $std->ideEvento->indRetif = 1;
        $std->ideEvento->tpAmb = 2;
        $std->ideEvento->procEmi = 1;
        $std->ideEvento->verProc = 'ALA_SST_1.0';

        // ideEmpregador
        $std->ideEmpregador = new \stdClass();
        $std->ideEmpregador->tpInsc = 1;
        $std->ideEmpregador->nrInsc = substr($cnpj, 0, 8);

        // ideVinculo
        $std->ideVinculo = new \stdClass();
        $std->ideVinculo->cpfTrab = $data['cpfTrabalhador'] ?? '';
        $std->ideVinculo->matricula = $data['matricula'] ?? '';

        // cat
        $std->cat = new \stdClass();
        $std->cat->dtAcid = $data['dataAcidente'] ?? '';
        $std->cat->tpAcid = $data['tipoAcidente'] ?? '1';
        $std->cat->hrAcid = $data['horaAcidente'] ?? '';
        $std->cat->hrsTrabAntesAcid = $data['horasTrabalhadasAntes'] ?? '0000';
        $std->cat->tpCat = $data['tipoCat'] ?? '1';
        $std->cat->indCatObito = ($data['obito'] ?? false) ? 'S' : 'N';
        $std->cat->indComunPolicia = ($data['comunicouPolicia'] ?? false) ? 'S' : 'N';
        $std->cat->codSitGeradora = $data['situacaoGeradora'] ?? '';
        $std->cat->iniciatCAT = $data['iniciativa'] ?? '1';
        $std->cat->obsCAT = $data['observacao'] ?? '';

        // localAcidente
        $std->cat->localAcidente = new \stdClass();
        $std->cat->localAcidente->tpLocal = $data['tipoLocal'] ?? '1';
        $std->cat->localAcidente->dscLocal = $data['descricaoLocal'] ?? '';
        $std->cat->localAcidente->codMunic = $data['codigoMunicipio'] ?? '';
        $std->cat->localAcidente->uf = $data['uf'] ?? '';

        // parteAtingida
        $std->cat->parteAtingida = new \stdClass();
        $std->cat->parteAtingida->codParteAting = $data['parteAtingida'] ?? '';
        $std->cat->parteAtingida->lateralidade = $data['lateralidade'] ?? '0';

        // agenteCausador
        $std->cat->agenteCausador = new \stdClass();
        $std->cat->agenteCausador->codAgntCausworking = $data['agenteCausador'] ?? '';

        // atestado
        $std->cat->atestado = new \stdClass();
        $std->cat->atestado->dtAtestado = $data['dataAtestado'] ?? '';
        $std->cat->atestado->hrAtestado = $data['horaAtestado'] ?? '';
        $std->cat->atestado->indInternacao = ($data['internacao'] ?? false) ? 'S' : 'N';
        $std->cat->atestado->durTrat = $data['duracaoTratamento'] ?? '0';
        $std->cat->atestado->indAfast = ($data['afastamento'] ?? false) ? 'S' : 'N';
        $std->cat->atestado->dscLesao = $data['descricaoLesao'] ?? '';
        $std->cat->atestado->codCID = $data['cid'] ?? '';

        // emitente
        $std->cat->atestado->emitente = new \stdClass();
        $std->cat->atestado->emitente->nmEmit = $data['medicoNome'] ?? '';
        $std->cat->atestado->emitente->ideOC = $data['medicoConselho'] ?? '1';
        $std->cat->atestado->emitente->nrOC = $data['medicoCRM'] ?? '';
        $std->cat->atestado->emitente->ufOC = $data['medicoUF'] ?? '';

        $event = Event::evtCAT(2210, $std);
        return $event->toXML();
    }

    /**
     * Build S-2220 - Monitoramento da Saúde do Trabalhador
     */
    private function buildS2220(array $data, string $cnpj): string
    {
        $std = new \stdClass();

        $std->ideEvento = new \stdClass();
        $std->ideEvento->indRetif = 1;
        $std->ideEvento->tpAmb = 2;
        $std->ideEvento->procEmi = 1;
        $std->ideEvento->verProc = 'ALA_SST_1.0';

        $std->ideEmpregador = new \stdClass();
        $std->ideEmpregador->tpInsc = 1;
        $std->ideEmpregador->nrInsc = substr($cnpj, 0, 8);

        $std->ideVinculo = new \stdClass();
        $std->ideVinculo->cpfTrab = $data['cpfTrabalhador'] ?? '';
        $std->ideVinculo->matricula = $data['matricula'] ?? '';

        $std->exMedOcup = new \stdClass();
        $std->exMedOcup->tpExameOcup = $data['tipoExame'] ?? '0';
        $std->exMedOcup->aso = new \stdClass();
        $std->exMedOcup->aso->dtAso = $data['dataAso'] ?? '';
        $std->exMedOcup->aso->resAso = $data['resultadoAso'] ?? '1';

        // Médico emitente
        $std->exMedOcup->aso->medico = new \stdClass();
        $std->exMedOcup->aso->medico->nmMed = $data['medicoNome'] ?? '';
        $std->exMedOcup->aso->medico->nrCRM = $data['medicoCRM'] ?? '';
        $std->exMedOcup->aso->medico->ufCRM = $data['medicoUF'] ?? '';

        $event = Event::evtMonit(2220, $std);
        return $event->toXML();
    }

    /**
     * Build S-2240 - Condições Ambientais do Trabalho
     */
    private function buildS2240(array $data, string $cnpj): string
    {
        $std = new \stdClass();

        $std->ideEvento = new \stdClass();
        $std->ideEvento->indRetif = 1;
        $std->ideEvento->tpAmb = 2;
        $std->ideEvento->procEmi = 1;
        $std->ideEvento->verProc = 'ALA_SST_1.0';

        $std->ideEmpregador = new \stdClass();
        $std->ideEmpregador->tpInsc = 1;
        $std->ideEmpregador->nrInsc = substr($cnpj, 0, 8);

        $std->ideVinculo = new \stdClass();
        $std->ideVinculo->cpfTrab = $data['cpfTrabalhador'] ?? '';
        $std->ideVinculo->matricula = $data['matricula'] ?? '';

        $std->infoExpRisco = new \stdClass();
        $std->infoExpRisco->dtIniCondicao = $data['dataInicio'] ?? '';

        // Informações do ambiente
        $std->infoExpRisco->infoAmb = new \stdClass();
        $std->infoExpRisco->infoAmb->codAmb = $data['codigoAmbiente'] ?? '';
        
        // Fatores de risco
        if (!empty($data['fatoresRisco'])) {
            $std->infoExpRisco->infoAtiv = new \stdClass();
            $std->infoExpRisco->infoAtiv->dscAtivDes = $data['descricaoAtividade'] ?? '';
        }

        $std->infoExpRisco->agNoc = [];
        foreach (($data['agentesNocivos'] ?? []) as $agente) {
            $ag = new \stdClass();
            $ag->codAgNoc = $agente['codigo'] ?? '';
            $ag->dscAgNoc = $agente['descricao'] ?? '';
            $ag->tpAval = $agente['tipoAvaliacao'] ?? '1';
            $std->infoExpRisco->agNoc[] = $ag;
        }

        $event = Event::evtExpRisco(2240, $std);
        return $event->toXML();
    }

    /**
     * Parse eSocial submission response
     */
    private function parseSubmitResponse(string $response): array
    {
        $xml = simplexml_load_string($response);
        
        if ($xml === false) {
            return [
                'success' => false,
                'error' => 'Não foi possível interpretar a resposta do eSocial',
                'raw_response' => $response,
            ];
        }

        // Register namespaces
        $ns = $xml->getNamespaces(true);
        
        // Try to extract protocol
        $protocol = null;
        $receipt = null;
        $error = null;

        // Search for retornoEnvioLoteEventos
        foreach ($xml->xpath('//*[local-name()="protocoloEnvio"]') as $node) {
            $protocol = (string) $node;
        }
        
        foreach ($xml->xpath('//*[local-name()="cdResposta"]') as $node) {
            $code = (string) $node;
            if ((int) $code >= 200 && (int) $code < 300) {
                // Success
            } else {
                foreach ($xml->xpath('//*[local-name()="descResposta"]') as $descNode) {
                    $error = (string) $descNode;
                }
            }
        }

        if ($error) {
            return [
                'success' => false,
                'error' => $error,
                'protocol' => $protocol,
            ];
        }

        return [
            'success' => true,
            'protocol' => $protocol,
            'message' => 'Evento enviado com sucesso ao eSocial',
        ];
    }

    /**
     * Parse eSocial status check response
     */
    private function parseStatusResponse(string $response): array
    {
        $xml = simplexml_load_string($response);
        
        if ($xml === false) {
            return [
                'success' => false,
                'error' => 'Não foi possível interpretar a resposta do eSocial',
            ];
        }

        $status = 'unknown';
        $receipt = null;
        $error = null;

        foreach ($xml->xpath('//*[local-name()="nrRecibo"]') as $node) {
            $receipt = (string) $node;
            $status = 'processed';
        }

        foreach ($xml->xpath('//*[local-name()="cdResposta"]') as $node) {
            $code = (string) $node;
            if ((int) $code === 101) {
                $status = 'processing';
            } elseif ((int) $code >= 200 && (int) $code < 300) {
                $status = 'processed';
            } else {
                $status = 'error';
                foreach ($xml->xpath('//*[local-name()="descResposta"]') as $descNode) {
                    $error = (string) $descNode;
                }
            }
        }

        return [
            'success' => $status !== 'error',
            'status' => $status,
            'receipt' => $receipt,
            'error' => $error,
        ];
    }

    /**
     * Generate a unique lot ID
     */
    private function generateLotId(): string
    {
        return 'LOT' . date('YmdHis') . substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6);
    }
}
