-- =====================================================================
--  14 · Catálogo CIE-10 de salud mental
--
--  El catálogo tenía 19 diagnósticos: los más frecuentes en consulta, pero
--  nada más. Cuando el diagnóstico no estaba, o se escribía a mano o se
--  forzaba uno parecido, y ninguna de las dos cosas sirve en un informe
--  que va a un colegio, una aseguradora o un juzgado.
--
--  Aquí entra el capítulo V de la CIE-10 (F00–F99, "Trastornos mentales y
--  del comportamiento") con el detalle que un psicólogo usa, más los
--  códigos Z de factores que influyen en el estado de salud, que son los
--  que corresponden cuando la consulta no es por un trastorno: problemas
--  de pareja, duelo, maltrato, dificultades de crianza.
--
--  Los códigos son los de la Organización Mundial de la Salud, que es el
--  estándar que usa el MINSA. Se insertan sin pisar los que ya estaban.
--
--  Ejecutar después de 01..13. Se puede repetir sin peligro.
-- =====================================================================

USE centro_psicologico;

INSERT INTO cie10_catalogo (codigo, descripcion, capitulo) VALUES
-- --- F00–F09 · Trastornos mentales orgánicos --------------------------
('F00.9','Demencia en la enfermedad de Alzheimer, no especificada','Trastornos mentales orgánicos'),
('F01.9','Demencia vascular, no especificada','Trastornos mentales orgánicos'),
('F03',  'Demencia, no especificada','Trastornos mentales orgánicos'),
('F05.9','Delirio, no especificado','Trastornos mentales orgánicos'),
('F06.3','Trastornos del humor orgánicos','Trastornos mentales orgánicos'),
('F06.4','Trastorno de ansiedad orgánico','Trastornos mentales orgánicos'),
('F07.0','Trastorno orgánico de la personalidad','Trastornos mentales orgánicos'),
('F07.2','Síndrome postconmocional','Trastornos mentales orgánicos'),
('F09',  'Trastorno mental orgánico o sintomático, no especificado','Trastornos mentales orgánicos'),

-- --- F10–F19 · Consumo de sustancias ---------------------------------
('F10.1','Trastornos mentales y del comportamiento debidos al alcohol: uso nocivo','Consumo de sustancias'),
('F10.2','Trastornos mentales y del comportamiento debidos al alcohol: síndrome de dependencia','Consumo de sustancias'),
('F10.3','Trastornos mentales y del comportamiento debidos al alcohol: síndrome de abstinencia','Consumo de sustancias'),
('F12.1','Trastornos debidos al uso de cannabinoides: uso nocivo','Consumo de sustancias'),
('F12.2','Trastornos debidos al uso de cannabinoides: síndrome de dependencia','Consumo de sustancias'),
('F14.1','Trastornos debidos al uso de cocaína: uso nocivo','Consumo de sustancias'),
('F14.2','Trastornos debidos al uso de cocaína: síndrome de dependencia','Consumo de sustancias'),
('F17.2','Trastornos debidos al uso de tabaco: síndrome de dependencia','Consumo de sustancias'),
('F19.2','Trastornos debidos al uso de múltiples drogas: síndrome de dependencia','Consumo de sustancias'),

-- --- F20–F29 · Esquizofrenia y trastornos delirantes ------------------
('F20.0','Esquizofrenia paranoide','Esquizofrenia y trastornos delirantes'),
('F20.9','Esquizofrenia, no especificada','Esquizofrenia y trastornos delirantes'),
('F21',  'Trastorno esquizotípico','Esquizofrenia y trastornos delirantes'),
('F22.0','Trastorno delirante','Esquizofrenia y trastornos delirantes'),
('F23.9','Trastorno psicótico agudo y transitorio, no especificado','Esquizofrenia y trastornos delirantes'),
('F25.9','Trastorno esquizoafectivo, no especificado','Esquizofrenia y trastornos delirantes'),
('F29',  'Psicosis no orgánica, no especificada','Esquizofrenia y trastornos delirantes'),

-- --- F30–F39 · Trastornos del humor ----------------------------------
('F30.1','Manía sin síntomas psicóticos','Trastornos del humor'),
('F31.0','Trastorno afectivo bipolar, episodio actual hipomaníaco','Trastornos del humor'),
('F31.3','Trastorno afectivo bipolar, episodio actual depresivo leve o moderado','Trastornos del humor'),
('F31.4','Trastorno afectivo bipolar, episodio actual depresivo grave sin síntomas psicóticos','Trastornos del humor'),
('F31.9','Trastorno afectivo bipolar, no especificado','Trastornos del humor'),
('F32.3','Episodio depresivo grave con síntomas psicóticos','Trastornos del humor'),
('F32.8','Otros episodios depresivos','Trastornos del humor'),
('F32.9','Episodio depresivo, no especificado','Trastornos del humor'),
('F33.1','Trastorno depresivo recurrente, episodio actual moderado','Trastornos del humor'),
('F33.2','Trastorno depresivo recurrente, episodio actual grave sin síntomas psicóticos','Trastornos del humor'),
('F33.9','Trastorno depresivo recurrente, no especificado','Trastornos del humor'),
('F34.0','Ciclotimia','Trastornos del humor'),
('F34.1','Distimia','Trastornos del humor'),
('F39',  'Trastorno del humor (afectivo), no especificado','Trastornos del humor'),

-- --- F40–F48 · Trastornos neuróticos y relacionados con el estrés -----
('F40.0','Agorafobia','Trastornos neuróticos y del estrés'),
('F40.1','Fobias sociales','Trastornos neuróticos y del estrés'),
('F40.2','Fobias específicas (aisladas)','Trastornos neuróticos y del estrés'),
('F40.9','Trastorno fóbico de ansiedad, no especificado','Trastornos neuróticos y del estrés'),
('F41.3','Otros trastornos mixtos de ansiedad','Trastornos neuróticos y del estrés'),
('F41.9','Trastorno de ansiedad, no especificado','Trastornos neuróticos y del estrés'),
('F42.0','Trastorno obsesivo-compulsivo: con predominio de pensamientos o rumiaciones obsesivas','Trastornos neuróticos y del estrés'),
('F42.1','Trastorno obsesivo-compulsivo: con predominio de actos compulsivos','Trastornos neuróticos y del estrés'),
('F42.2','Trastorno obsesivo-compulsivo: actos e ideas obsesivas mixtos','Trastornos neuróticos y del estrés'),
('F42.9','Trastorno obsesivo-compulsivo, no especificado','Trastornos neuróticos y del estrés'),
('F43.8','Otras reacciones al estrés grave','Trastornos neuróticos y del estrés'),
('F43.9','Reacción al estrés grave, no especificada','Trastornos neuróticos y del estrés'),
('F44.4','Trastornos disociativos (de conversión) motores','Trastornos neuróticos y del estrés'),
('F44.9','Trastorno disociativo (de conversión), no especificado','Trastornos neuróticos y del estrés'),
('F45.0','Trastorno de somatización','Trastornos neuróticos y del estrés'),
('F45.2','Trastorno hipocondriaco','Trastornos neuróticos y del estrés'),
('F45.4','Trastorno de dolor persistente somatomorfo','Trastornos neuróticos y del estrés'),
('F45.9','Trastorno somatomorfo, no especificado','Trastornos neuróticos y del estrés'),
('F48.0','Neurastenia','Trastornos neuróticos y del estrés'),
('F48.9','Trastorno neurótico, no especificado','Trastornos neuróticos y del estrés'),

-- --- F50–F59 · Síndromes del comportamiento ---------------------------
('F50.0','Anorexia nerviosa','Síndromes del comportamiento'),
('F50.2','Bulimia nerviosa','Síndromes del comportamiento'),
('F50.9','Trastorno de la conducta alimentaria, no especificado','Síndromes del comportamiento'),
('F51.0','Insomnio no orgánico','Síndromes del comportamiento'),
('F51.4','Terrores del sueño (terrores nocturnos)','Síndromes del comportamiento'),
('F51.5','Pesadillas','Síndromes del comportamiento'),
('F52.0','Ausencia o pérdida del deseo sexual','Síndromes del comportamiento'),
('F53.0','Trastornos mentales leves del puerperio, no clasificados en otra parte','Síndromes del comportamiento'),

-- --- F60–F69 · Trastornos de la personalidad --------------------------
('F60.0','Trastorno paranoide de la personalidad','Trastornos de la personalidad'),
('F60.1','Trastorno esquizoide de la personalidad','Trastornos de la personalidad'),
('F60.2','Trastorno disocial de la personalidad','Trastornos de la personalidad'),
('F60.3','Trastorno de inestabilidad emocional de la personalidad','Trastornos de la personalidad'),
('F60.4','Trastorno histriónico de la personalidad','Trastornos de la personalidad'),
('F60.5','Trastorno anancástico (obsesivo-compulsivo) de la personalidad','Trastornos de la personalidad'),
('F60.6','Trastorno ansioso (evitativo) de la personalidad','Trastornos de la personalidad'),
('F60.7','Trastorno dependiente de la personalidad','Trastornos de la personalidad'),
('F60.9','Trastorno de la personalidad, no especificado','Trastornos de la personalidad'),
('F63.0','Ludopatía (juego patológico)','Trastornos de la personalidad'),
('F63.8','Otros trastornos de los hábitos y del control de los impulsos','Trastornos de la personalidad'),

-- --- F70–F79 · Discapacidad intelectual -------------------------------
('F70.0','Retraso mental leve, deterioro del comportamiento nulo o mínimo','Discapacidad intelectual'),
('F71.9','Retraso mental moderado, sin deterioro del comportamiento','Discapacidad intelectual'),
('F72.9','Retraso mental grave, sin deterioro del comportamiento','Discapacidad intelectual'),
('F73.9','Retraso mental profundo, sin deterioro del comportamiento','Discapacidad intelectual'),
('F79.9','Retraso mental no especificado, sin deterioro del comportamiento','Discapacidad intelectual'),

-- --- F80–F89 · Trastornos del desarrollo psicológico ------------------
('F80.0','Trastorno específico de la pronunciación','Trastornos del desarrollo'),
('F80.1','Trastorno del lenguaje expresivo','Trastornos del desarrollo'),
('F80.2','Trastorno de la recepción del lenguaje','Trastornos del desarrollo'),
('F81.1','Trastorno específico del deletreo (ortografía)','Trastornos del desarrollo'),
('F81.2','Trastorno específico de las habilidades aritméticas','Trastornos del desarrollo'),
('F81.3','Trastorno mixto de las habilidades escolares','Trastornos del desarrollo'),
('F81.9','Trastorno del desarrollo de las habilidades escolares, no especificado','Trastornos del desarrollo'),
('F82',  'Trastorno específico del desarrollo psicomotor','Trastornos del desarrollo'),
('F83',  'Trastornos específicos mixtos del desarrollo','Trastornos del desarrollo'),
('F84.1','Autismo atípico','Trastornos del desarrollo'),
('F84.9','Trastorno generalizado del desarrollo, no especificado','Trastornos del desarrollo'),
('F88',  'Otros trastornos del desarrollo psicológico','Trastornos del desarrollo'),
('F89',  'Trastorno del desarrollo psicológico, no especificado','Trastornos del desarrollo'),

-- --- F90–F98 · Infancia y adolescencia --------------------------------
('F90.8','Otros trastornos hipercinéticos','Infancia y adolescencia'),
('F90.9','Trastorno hipercinético, no especificado','Infancia y adolescencia'),
('F91.0','Trastorno disocial limitado al contexto familiar','Infancia y adolescencia'),
('F91.1','Trastorno disocial en niños no socializados','Infancia y adolescencia'),
('F91.2','Trastorno disocial en niños socializados','Infancia y adolescencia'),
('F91.3','Trastorno disocial desafiante y oposicionista','Infancia y adolescencia'),
('F91.9','Trastorno disocial, no especificado','Infancia y adolescencia'),
('F92.0','Trastorno disocial depresivo','Infancia y adolescencia'),
('F93.0','Trastorno de ansiedad de separación en la niñez','Infancia y adolescencia'),
('F93.1','Trastorno de ansiedad fóbica en la niñez','Infancia y adolescencia'),
('F93.2','Trastorno de ansiedad social en la niñez','Infancia y adolescencia'),
('F93.3','Trastorno de rivalidad entre hermanos','Infancia y adolescencia'),
('F93.9','Trastorno emocional en la niñez, no especificado','Infancia y adolescencia'),
('F94.0','Mutismo electivo','Infancia y adolescencia'),
('F94.1','Trastorno de vinculación reactivo en la niñez','Infancia y adolescencia'),
('F94.2','Trastorno de vinculación desinhibido en la niñez','Infancia y adolescencia'),
('F95.0','Trastorno de tics transitorios','Infancia y adolescencia'),
('F95.1','Trastorno de tics motores o vocales crónicos','Infancia y adolescencia'),
('F95.2','Trastorno de tics motores y vocales múltiples combinados (síndrome de Gilles de la Tourette)','Infancia y adolescencia'),
('F98.0','Enuresis no orgánica','Infancia y adolescencia'),
('F98.1','Encopresis no orgánica','Infancia y adolescencia'),
('F98.4','Trastorno de los movimientos estereotipados','Infancia y adolescencia'),
('F98.5','Tartamudez (espasmofemia)','Infancia y adolescencia'),
('F98.8','Otros trastornos emocionales y del comportamiento de comienzo en la niñez','Infancia y adolescencia'),
('F99',  'Trastorno mental, no especificado','Trastorno mental sin especificar'),

-- --- Z · Cuando la consulta no es por un trastorno --------------------
--  Estos son los que corresponden en orientación, terapia de pareja,
--  duelo o evaluación: poner un código F donde no hay trastorno es
--  etiquetar a alguien con un diagnóstico que no tiene.
('Z00.8','Otros exámenes generales','Factores que influyen en el estado de salud'),
('Z03.2','Observación por sospecha de trastorno mental, descartada','Factores que influyen en el estado de salud'),
('Z55.8','Otros problemas relacionados con la educación y la alfabetización','Factores que influyen en el estado de salud'),
('Z56.0','Problemas relacionados con el desempleo, no especificados','Factores que influyen en el estado de salud'),
('Z56.6','Otras tensiones físicas y mentales relacionadas con el trabajo','Factores que influyen en el estado de salud'),
('Z60.0','Problemas de ajuste a las transiciones del ciclo vital','Factores que influyen en el estado de salud'),
('Z60.2','Problemas relacionados con vivir solo','Factores que influyen en el estado de salud'),
('Z60.4','Exclusión y rechazo social','Factores que influyen en el estado de salud'),
('Z61.0','Pérdida de relación afectiva en la infancia','Factores que influyen en el estado de salud'),
('Z61.4','Problemas relacionados con abuso sexual del niño por persona del grupo de apoyo primario','Factores que influyen en el estado de salud'),
('Z61.5','Problemas relacionados con abuso sexual del niño por persona ajena al grupo primario','Factores que influyen en el estado de salud'),
('Z61.6','Problemas relacionados con abuso físico del niño','Factores que influyen en el estado de salud'),
('Z62.0','Supervisión y control inadecuados de los padres','Factores que influyen en el estado de salud'),
('Z62.3','Hostilidad hacia el niño y rechazo del mismo','Factores que influyen en el estado de salud'),
('Z62.4','Abandono emocional del niño','Factores que influyen en el estado de salud'),
('Z62.8','Otros problemas relacionados con la crianza del niño','Factores que influyen en el estado de salud'),
('Z63.1','Problemas en la relación con los padres y los familiares políticos','Factores que influyen en el estado de salud'),
('Z63.2','Apoyo familiar inadecuado','Factores que influyen en el estado de salud'),
('Z63.4','Desaparición o fallecimiento de un miembro de la familia','Factores que influyen en el estado de salud'),
('Z63.5','Ruptura familiar por separación o divorcio','Factores que influyen en el estado de salud'),
('Z63.8','Otros problemas especificados relacionados con el grupo primario de apoyo','Factores que influyen en el estado de salud'),
('Z64.0','Problemas relacionados con embarazo no deseado','Factores que influyen en el estado de salud'),
('Z65.4','Víctima de crimen o terrorismo','Factores que influyen en el estado de salud'),
('Z70.9','Consulta sobre actitud, conducta y orientación sexual, no especificada','Factores que influyen en el estado de salud'),
('Z71.1','Persona que teme estar enferma, a quien no se le hace diagnóstico','Factores que influyen en el estado de salud'),
('Z71.4','Consulta para asesoría y vigilancia por abuso de alcohol','Factores que influyen en el estado de salud'),
('Z71.5','Consulta para asesoría y vigilancia por abuso de drogas','Factores que influyen en el estado de salud'),
('Z71.9','Consulta, no especificada','Factores que influyen en el estado de salud'),
('Z73.0','Problemas relacionados con el agotamiento (burnout)','Factores que influyen en el estado de salud'),
('Z73.3','Estrés, no clasificado en otra parte','Factores que influyen en el estado de salud'),
('Z73.6','Problemas relacionados con la limitación de las actividades debido a discapacidad','Factores que influyen en el estado de salud'),
('Z81.8','Historia familiar de otros trastornos mentales y del comportamiento','Factores que influyen en el estado de salud'),
('Z91.5','Historia personal de lesión autoinfligida intencionalmente','Factores que influyen en el estado de salud')
ON DUPLICATE KEY UPDATE
  descripcion = VALUES(descripcion),
  capitulo    = COALESCE(cie10_catalogo.capitulo, VALUES(capitulo));

-- --- Categorías de tres dígitos ---------------------------------------
--  El panel ya ofrecía estas ("F20 Esquizofrenia") pero no estaban en el
--  catálogo, así que al guardar el diagnóstico se quedaba sin código: la
--  descripción sobrevivía y el CIE-10 se perdía, en silencio. Son
--  categorías válidas de la clasificación y sirven cuando el diagnóstico
--  todavía no se precisa al tercer carácter.
INSERT INTO cie10_catalogo (codigo, descripcion, capitulo) VALUES
('F00','Demencia en la enfermedad de Alzheimer','Trastornos mentales orgánicos'),
('F01','Demencia vascular','Trastornos mentales orgánicos'),
('F06','Otros trastornos mentales debidos a lesión o disfunción cerebral','Trastornos mentales orgánicos'),
('F07','Trastornos de la personalidad y del comportamiento debidos a enfermedad o lesión cerebral','Trastornos mentales orgánicos'),
('F10','Trastornos mentales y del comportamiento debidos al uso de alcohol','Consumo de sustancias'),
('F11','Trastornos mentales y del comportamiento debidos al uso de opioides','Consumo de sustancias'),
('F12','Trastornos mentales y del comportamiento debidos al uso de cannabinoides','Consumo de sustancias'),
('F13','Trastornos mentales y del comportamiento debidos al uso de sedantes o hipnóticos','Consumo de sustancias'),
('F14','Trastornos mentales y del comportamiento debidos al uso de cocaína','Consumo de sustancias'),
('F15','Trastornos mentales y del comportamiento debidos al uso de otros estimulantes, incluida la cafeína','Consumo de sustancias'),
('F16','Trastornos mentales y del comportamiento debidos al uso de alucinógenos','Consumo de sustancias'),
('F17','Trastornos mentales y del comportamiento debidos al uso de tabaco','Consumo de sustancias'),
('F18','Trastornos mentales y del comportamiento debidos al uso de disolventes volátiles','Consumo de sustancias'),
('F19','Trastornos mentales y del comportamiento debidos al uso de múltiples drogas','Consumo de sustancias'),
('F20','Esquizofrenia','Esquizofrenia y trastornos delirantes'),
('F22','Trastornos delirantes persistentes','Esquizofrenia y trastornos delirantes'),
('F23','Trastornos psicóticos agudos y transitorios','Esquizofrenia y trastornos delirantes'),
('F25','Trastornos esquizoafectivos','Esquizofrenia y trastornos delirantes'),
('F30','Episodio maníaco','Trastornos del humor'),
('F31','Trastorno afectivo bipolar','Trastornos del humor'),
('F32','Episodio depresivo','Trastornos del humor'),
('F33','Trastorno depresivo recurrente','Trastornos del humor'),
('F42','Trastorno obsesivo-compulsivo','Trastornos neuróticos y del estrés'),
('F44','Trastornos disociativos (de conversión)','Trastornos neuróticos y del estrés'),
('F51','Trastornos no orgánicos del sueño','Síndromes del comportamiento'),
('F52','Disfunción sexual no debida a trastorno ni a enfermedad orgánica','Síndromes del comportamiento'),
('F63','Trastornos de los hábitos y del control de los impulsos','Trastornos de la personalidad'),
('F64','Trastornos de la identidad de género','Trastornos de la personalidad'),
('F65','Trastornos de la preferencia sexual','Trastornos de la personalidad'),
('F70','Retraso mental leve','Discapacidad intelectual'),
('F71','Retraso mental moderado','Discapacidad intelectual'),
('F72','Retraso mental grave','Discapacidad intelectual'),
('F80','Trastornos específicos del desarrollo del habla y del lenguaje','Trastornos del desarrollo'),
('F81','Trastornos específicos del desarrollo de las habilidades escolares','Trastornos del desarrollo'),
('F91','Trastornos disociales','Infancia y adolescencia'),
('F92','Trastornos mixtos disociales y de las emociones','Infancia y adolescencia'),
('F95','Trastornos por tics','Infancia y adolescencia')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- Los 19 que ya estaban traían nombres de capítulo distintos para las
-- mismas familias ("Retraso mental" junto a "Discapacidad intelectual").
-- Con dos nombres para lo mismo el buscador parte la lista en dos y el
-- código que se busca aparece donde no se lo espera. Se unifican por el
-- código, que es lo que manda.
UPDATE cie10_catalogo SET capitulo =
  CASE
    WHEN codigo LIKE 'F0%' THEN 'Trastornos mentales orgánicos'
    WHEN codigo LIKE 'F1%' THEN 'Consumo de sustancias'
    WHEN codigo LIKE 'F2%' THEN 'Esquizofrenia y trastornos delirantes'
    WHEN codigo LIKE 'F3%' THEN 'Trastornos del humor'
    WHEN codigo LIKE 'F4%' THEN 'Trastornos neuróticos y del estrés'
    WHEN codigo LIKE 'F5%' THEN 'Síndromes del comportamiento'
    WHEN codigo LIKE 'F6%' THEN 'Trastornos de la personalidad'
    WHEN codigo LIKE 'F7%' THEN 'Discapacidad intelectual'
    WHEN codigo LIKE 'F8%' THEN 'Trastornos del desarrollo'
    WHEN codigo  =    'F99' THEN 'Trastorno mental sin especificar'
    WHEN codigo LIKE 'F9%' THEN 'Infancia y adolescencia'
    WHEN codigo LIKE 'Z%'  THEN 'Factores que influyen en el estado de salud'
    ELSE capitulo
  END
 WHERE codigo LIKE 'F%' OR codigo LIKE 'Z%';
