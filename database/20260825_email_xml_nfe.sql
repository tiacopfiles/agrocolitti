ALTER TABLE clientes
    ADD COLUMN email_nfe VARCHAR(255) NULL DEFAULT NULL AFTER telefone,
    ADD COLUMN email_nfe_2 VARCHAR(255) NULL DEFAULT NULL AFTER email_nfe,
    ADD COLUMN email_nfe_3 VARCHAR(255) NULL DEFAULT NULL AFTER email_nfe_2;

ALTER TABLE nfe_documentos
    ADD COLUMN email_xml_status VARCHAR(20) NULL DEFAULT NULL AFTER caminho_xml,
    ADD COLUMN email_xml_destinatario VARCHAR(800) NULL DEFAULT NULL AFTER email_xml_status,
    ADD COLUMN email_xml_tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER email_xml_destinatario,
    ADD COLUMN email_xml_enviado_em DATETIME NULL DEFAULT NULL AFTER email_xml_tentativas,
    ADD COLUMN email_xml_erro VARCHAR(1000) NULL DEFAULT NULL AFTER email_xml_enviado_em;
