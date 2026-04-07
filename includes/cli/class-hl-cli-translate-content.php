<?php
/**
 * WP-CLI command: Translate pathway descriptions and TSA instruments.
 *
 * Registers pathway strings with WPML String Translation and inserts
 * language-specific Teacher Self-Assessment instrument rows.
 *
 * Usage: wp hl-core translate-content [--dry-run]
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_CLI_Translate_Content {

    /**
     * Register with WP-CLI.
     */
    public static function register() {
        if ( ! class_exists( 'WP_CLI' ) ) {
            return;
        }
        WP_CLI::add_command( 'hl-core translate-content', array( __CLASS__, 'run' ) );
    }

    /**
     * Translate pathway content and TSA instruments to ES and PT-BR.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be done without making changes.
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public static function run( $args, $assoc_args ) {
        $dry_run = isset( $assoc_args['dry-run'] );

        if ( $dry_run ) {
            WP_CLI::log( '=== DRY RUN — no changes will be made ===' );
        }

        // Part 1: Pathway WPML string translations.
        self::translate_pathways( $dry_run );

        // Part 2: TSA instrument translations.
        self::translate_tsa_instruments( $dry_run );

        // Part 3: Child assessment instrument translations.
        self::translate_child_instruments( $dry_run );

        // Part 4: ELCPB-specific TSA Post instrument.
        self::create_elcpb_tsa_post( $dry_run );

        WP_CLI::success( $dry_run ? 'Dry run complete.' : 'All translations applied.' );
    }

    // =========================================================================
    // Part 1: Pathway WPML String Translations
    // =========================================================================

    private static function translate_pathways( $dry_run ) {
        WP_CLI::log( "\n--- Part 1: Pathway WPML String Translations ---" );

        if ( ! function_exists( 'icl_register_string' ) ) {
            WP_CLI::warning( 'WPML String Translation not active. Skipping pathway translations.' );
            return;
        }

        $translations = self::get_pathway_translations();

        foreach ( $translations as $pathway_id => $fields ) {
            WP_CLI::log( "Pathway {$pathway_id}:" );

            foreach ( $fields as $field => $data ) {
                $name = "pathway_{$pathway_id}_{$field}";
                $original = $data['en'];

                if ( $dry_run ) {
                    WP_CLI::log( "  Would register: {$name} (" . strlen( $original ) . " chars)" );
                    if ( ! empty( $data['es'] ) ) {
                        WP_CLI::log( "  Would add ES translation (" . strlen( $data['es'] ) . " chars)" );
                    }
                    if ( ! empty( $data['pt-br'] ) ) {
                        WP_CLI::log( "  Would add PT-BR translation (" . strlen( $data['pt-br'] ) . " chars)" );
                    }
                    continue;
                }

                // Register the English string.
                icl_register_string( 'hl-core-pathways', $name, $original );

                // Look up the string ID.
                global $wpdb;
                $string_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}icl_strings WHERE context = %s AND name = %s",
                    'hl-core-pathways',
                    $name
                ) );

                if ( ! $string_id ) {
                    WP_CLI::warning( "  Could not find registered string ID for {$name}" );
                    continue;
                }

                // Add ES translation.
                if ( ! empty( $data['es'] ) ) {
                    icl_add_string_translation( $string_id, 'es', $data['es'], ICL_TM_COMPLETE );
                    WP_CLI::log( "  ES: {$field} — OK" );
                }

                // Add PT-BR translation.
                if ( ! empty( $data['pt-br'] ) ) {
                    icl_add_string_translation( $string_id, 'pt-br', $data['pt-br'], ICL_TM_COMPLETE );
                    WP_CLI::log( "  PT-BR: {$field} — OK" );
                }
            }
        }

        WP_CLI::log( 'Pathway translations complete.' );
    }

    /**
     * Pathway translation data for ELCPBC cycle (cycle_id = 8 on production).
     */
    private static function get_pathway_translations() {
        // Shared description first paragraph.
        $shared_desc_en = '<strong><em>Begin to</em> ECSEL Training &amp; Mastery Program</strong> is an institution-level ECSEL training program that utilizes a combination of physical classroom tools, asynchronous learning, an online social community, and ongoing live support. The program is designed for teachers and caregivers of children from birth to eight years of age. There are 12 courses in the <strong>full</strong> <em>begin to</em> ECSEL program: 8 courses for teachers and mentors, and an additional 4 mentor-focused courses on Reflective Practice (our adaptation of Reflective Supervision).';

        $shared_desc_es = '<strong><em>Begin to</em> ECSEL Programa de Capacitación y Dominio</strong> es un programa de capacitación ECSEL a nivel institucional que utiliza una combinación de herramientas físicas para el salón de clases, aprendizaje asíncrono, una comunidad social en línea y apoyo continuo en vivo. El programa está diseñado para maestros y cuidadores de niños desde el nacimiento hasta los ocho años de edad. Hay 12 cursos en el programa <strong>completo</strong> de <em>begin to</em> ECSEL: 8 cursos para maestros y mentores, y 4 cursos adicionales enfocados en mentores sobre Práctica Reflexiva (nuestra adaptación de Supervisión Reflexiva).';

        $shared_desc_pt = '<strong><em>Begin to</em> ECSEL Programa de Treinamento &amp; Domínio</strong> é um programa de treinamento ECSEL em nível institucional que utiliza uma combinação de materiais físicos para sala de aula, aprendizagem assíncrona, uma comunidade social online e suporte ao vivo contínuo. O programa é destinado a professores e cuidadores de crianças desde o nascimento até oito anos de idade. Há 12 cursos no programa <strong>completo</strong> <em>begin to</em> ECSEL: 8 cursos para professores e mentores, e 4 cursos adicionais focados em mentores sobre Prática Reflexiva (nossa adaptação da Supervisão Reflexiva).';

        // Shared full program objectives (pathways 28, 31, 33, 35, 37).
        $shared_obj_en = '<h2>Program Objectives</h2>' . "\n" . 'At the end of the full training program, learners will have the skills to successfully promote the building blocks of emotional intelligence and other critical emotional, cognitive, and social early learning skills in the children they work with, and within themselves.' . "\n" . '<ul>' . "\n" . ' 	<li>Learners will gain a better understanding of how to use <em>begin to</em> ECSEL language, techniques, and tools in their classroom to promote emotional intelligence of children at different developmental stages.</li>' . "\n" . ' 	<li>Learners will become more aware of their own emotions, enhancing their overall emotional well-being.</li>' . "\n" . ' 	<li>Learners will gain an ease in discussing their feelings and those of others, and a stronger foundation of knowledge about emotional, cognitive, and social learning.</li>' . "\n" . ' 	<li>Learners will identify strategies to help them communicate with children, families, and co-workers, and be able to demonstrate empathy, problem solving, and caring as they respond to children\'s emotional outbursts.</li>' . "\n" . ' 	<li>Learners will use reflective practices in their daily work with children, colleagues, and families.</li>' . "\n" . '</ul>' . "\n" . '<h2>Program Syllabus</h2>' . "\n" . 'Select the following link to get your full program syllabus:' . "\n" . '<ul>' . "\n" . ' 	<li><a href="https://share.housmaninstitute.com/assets/b2E/Course/Welcome/Syllabus/b2e-full-Program-Syllabus-general.pdf" target="_blank" rel="noopener noreferrer"><em>begin to</em> ECSEL Training &amp; Mastery Program Syllabus</a></li>' . "\n" . '</ul>' . "\n" . '&nbsp;' . "\n\n" . '<em>*When you are going through the training, here are some <strong>acronyms</strong> to keep in mind:</em>' . "\n" . '<ul>' . "\n" . ' 	<li><em>B2E = Begin to ECSEL </em></li>' . "\n" . ' 	<li><em>TC = Teacher Course</em></li>' . "\n" . ' 	<li><em>MC = Mentor Course</em></li>' . "\n" . ' 	<li><em><strong>For example: B2ETC3M2 = Begin to ECSEL Teacher Course 3 Module 2 </strong></em></li>' . "\n" . '</ul>';

        $shared_obj_es = '<h2>Objetivos del Programa</h2>' . "\n" . 'Al final del programa completo de capacitación, los participantes tendrán las habilidades para promover exitosamente los pilares de la Inteligencia Emocional y otras habilidades críticas de aprendizaje temprano emocional, cognitivo y social en los niños con los que trabajan, y en sí mismos.' . "\n" . '<ul>' . "\n" . ' 	<li>Los participantes obtendrán una mejor comprensión de cómo usar el lenguaje, las técnicas y las herramientas de <em>begin to</em> ECSEL en su salón de clases para promover la Inteligencia Emocional de los niños en diferentes etapas de desarrollo.</li>' . "\n" . ' 	<li>Los participantes serán más conscientes de sus propias emociones, mejorando su bienestar emocional general.</li>' . "\n" . ' 	<li>Los participantes adquirirán facilidad para hablar sobre sus sentimientos y los de los demás, y una base más sólida de conocimiento sobre el aprendizaje emocional, cognitivo y social.</li>' . "\n" . ' 	<li>Los participantes identificarán estrategias para ayudarles a comunicarse con los niños, las familias y los compañeros de trabajo, y podrán demostrar empatía, resolución de problemas y cuidado al responder a las explosiones emocionales de los niños.</li>' . "\n" . ' 	<li>Los participantes utilizarán prácticas reflexivas en su trabajo diario con los niños, colegas y familias.</li>' . "\n" . '</ul>' . "\n" . '<h2>Plan de Estudios del Programa</h2>' . "\n" . 'Seleccione el siguiente enlace para obtener su plan de estudios completo del programa:' . "\n" . '<ul>' . "\n" . ' 	<li><a href="https://share.housmaninstitute.com/assets/b2E/Course/Welcome/Syllabus/b2e-full-Program-Syllabus-general.pdf" target="_blank" rel="noopener noreferrer">Plan de Estudios del <em>begin to</em> ECSEL Programa de Capacitación y Dominio</a></li>' . "\n" . '</ul>' . "\n" . '&nbsp;' . "\n\n" . '<em>*Mientras realiza la capacitación, tenga en cuenta las siguientes <strong>siglas</strong>:</em>' . "\n" . '<ul>' . "\n" . ' 	<li><em>B2E = Begin to ECSEL </em></li>' . "\n" . ' 	<li><em>TC = Teacher Course</em></li>' . "\n" . ' 	<li><em>MC = Mentor Course</em></li>' . "\n" . ' 	<li><em><strong>Por ejemplo: B2ETC3M2 = Begin to ECSEL Teacher Course 3 Module 2 </strong></em></li>' . "\n" . '</ul>';

        $shared_obj_pt = '<h2>Objetivos do Programa</h2>' . "\n" . 'Ao final do programa completo de treinamento, os participantes terão as habilidades para promover com sucesso os fundamentos da Inteligência Emocional e outras habilidades essenciais de aprendizagem emocional, cognitiva e social nas crianças com quem trabalham, e em si mesmos.' . "\n" . '<ul>' . "\n" . ' 	<li>Os participantes obterão uma melhor compreensão de como usar a linguagem, técnicas e ferramentas do <em>begin to</em> ECSEL em sua sala de aula para promover a Inteligência Emocional de crianças em diferentes estágios de desenvolvimento.</li>' . "\n" . ' 	<li>Os participantes se tornarão mais conscientes de suas próprias emoções, aprimorando seu bem-estar emocional geral.</li>' . "\n" . ' 	<li>Os participantes ganharão facilidade para discutir seus sentimentos e os dos outros, e uma base mais sólida de conhecimento sobre aprendizagem emocional, cognitiva e social.</li>' . "\n" . ' 	<li>Os participantes identificarão estratégias para ajudá-los a se comunicar com crianças, famílias e colegas de trabalho, e serão capazes de demonstrar empatia, resolução de problemas e cuidado ao responder às explosões emocionais das crianças.</li>' . "\n" . ' 	<li>Os participantes utilizarão práticas reflexivas em seu trabalho diário com crianças, colegas e famílias.</li>' . "\n" . '</ul>' . "\n" . '<h2>Programa do Curso</h2>' . "\n" . 'Selecione o link a seguir para obter o programa completo do curso:' . "\n" . '<ul>' . "\n" . ' 	<li><a href="https://share.housmaninstitute.com/assets/b2E/Course/Welcome/Syllabus/b2e-full-Program-Syllabus-general.pdf" target="_blank" rel="noopener noreferrer">Programa do Curso <em>begin to</em> ECSEL Treinamento &amp; Domínio</a></li>' . "\n" . '</ul>' . "\n" . '&nbsp;' . "\n\n" . '<em>*Ao longo do treinamento, aqui estão algumas <strong>siglas</strong> para ter em mente:</em>' . "\n" . '<ul>' . "\n" . ' 	<li><em>B2E = Begin to ECSEL </em></li>' . "\n" . ' 	<li><em>TC = Teacher Course</em></li>' . "\n" . ' 	<li><em>MC = Mentor Course</em></li>' . "\n" . ' 	<li><em><strong>Por exemplo: B2ETC3M2 = Begin to ECSEL Teacher Course 3 Module 2 </strong></em></li>' . "\n" . '</ul>';

        // Streamlined description (pathways 31 + 37).
        $streamlined_p2_en = "\n\n" . '<strong style="color: #ff0000">This is a <span dir="ltr">streamlined version of the <em>begin to </em>ECSEL Training &amp; Mastery Program (Phase II) for school administrators and leaders to review in a less structured format</span>. All courses are in free navigation.</strong>';
        $streamlined_p2_es = "\n\n" . '<strong style="color: #ff0000">Esta es una <span dir="ltr">versión simplificada del <em>begin to </em>ECSEL Programa de Capacitación y Dominio (Fase II) para que administradores escolares y líderes la revisen en un formato menos estructurado</span>. Todos los cursos son de navegación libre.</strong>';
        $streamlined_p2_pt = "\n\n" . '<strong style="color: #ff0000">Esta é uma <span dir="ltr">versão simplificada do <em>begin to </em>ECSEL Programa de Treinamento &amp; Domínio (Fase II) para administradores e líderes escolares revisarem em um formato menos estruturado</span>. Todos os cursos estão em navegação livre.</strong>';

        return array(
            // Pathway 27 — Teacher Phase 1.
            27 => array(
                'pathway_name' => array(
                    'en'    => 'Begin to ECSEL Training & Mastery Program (Teacher Phase 1)',
                    'es'    => 'Begin to ECSEL Programa de Capacitación y Dominio (Maestro Fase 1)',
                    'pt-br' => 'Begin to ECSEL Programa de Treinamento e Domínio (Professor Fase 1)',
                ),
                'description' => array(
                    'en'    => $shared_desc_en . "\n\n" . 'In the Phase I of the program, teachers will be provided with 4 Teacher courses.',
                    'es'    => $shared_desc_es . "\n\n" . 'En la Fase I del programa, los maestros recibirán 4 cursos de Maestro.',
                    'pt-br' => $shared_desc_pt . "\n\n" . 'Na Fase I do programa, os professores receberão 4 cursos de Professor.',
                ),
                'objectives' => array(
                    'en'    => str_replace(
                        'At the end of the full training program, learners will have the skills',
                        'By the end of Phase I, educators will start to address their learned behaviors and reactions to heightened emotions by learning reflection-based strategies. They will also begin to effectively identify, understand, express, and regulate their own emotions and how to promote these same emotional intelligence skills in children.',
                        $shared_obj_en
                    ),
                    'es'    => '<h2>Objetivos del Programa</h2>' . "\n" . 'Al final de la Fase I, los educadores comenzarán a abordar sus comportamientos aprendidos y reacciones ante emociones intensas mediante el aprendizaje de estrategias basadas en la reflexión. También comenzarán a identificar, comprender, expresar y regular eficazmente sus propias emociones y a promover estas mismas habilidades de Inteligencia Emocional en los niños.' . "\n" . '<h2>Plan de Estudios del Programa</h2>' . "\n" . 'Seleccione el siguiente enlace para obtener su plan de estudios completo del programa:' . "\n" . '<ul>' . "\n" . ' 	<li><a href="https://share.housmaninstitute.com/assets/b2E/Course/Welcome/Syllabus/b2e-full-Program-Syllabus-general.pdf" target="_blank" rel="noopener noreferrer">Plan de Estudios del <em>begin to</em> ECSEL Programa de Capacitación y Dominio</a></li>' . "\n" . '</ul>' . "\n" . '&nbsp;' . "\n\n" . '<em>*Mientras realiza la capacitación, tenga en cuenta las siguientes <strong>siglas</strong>:</em>' . "\n" . '<ul>' . "\n" . ' 	<li><em>B2E = Begin to ECSEL </em></li>' . "\n" . ' 	<li><em>TC = Teacher Course</em></li>' . "\n" . ' 	<li><em>MC = Mentor Course</em></li>' . "\n" . ' 	<li><em><strong>Por ejemplo: B2ETC3M2 = Begin to ECSEL Teacher Course 3 Module 2 </strong></em></li>' . "\n" . '</ul>',
                    'pt-br' => '<h2>Objetivos do Programa</h2>' . "\n" . 'Ao final da Fase I, os educadores começarão a abordar seus comportamentos aprendidos e reações a emoções intensas por meio de estratégias baseadas em reflexão. Eles também começarão a identificar, compreender, expressar e regular efetivamente suas próprias emoções e a promover essas mesmas habilidades de Inteligência Emocional nas crianças.' . "\n" . '<h2>Programa do Curso</h2>' . "\n" . 'Selecione o link a seguir para obter o programa completo do curso:' . "\n" . '<ul>' . "\n" . ' 	<li><a href="https://share.housmaninstitute.com/assets/b2E/Course/Welcome/Syllabus/b2e-full-Program-Syllabus-general.pdf" target="_blank" rel="noopener noreferrer">Programa do Curso <em>begin to</em> ECSEL Treinamento &amp; Domínio</a></li>' . "\n" . '</ul>' . "\n" . '&nbsp;' . "\n\n" . '<em>*Ao longo do treinamento, aqui estão algumas <strong>siglas</strong> para ter em mente:</em>' . "\n" . '<ul>' . "\n" . ' 	<li><em>B2E = Begin to ECSEL </em></li>' . "\n" . ' 	<li><em>TC = Teacher Course</em></li>' . "\n" . ' 	<li><em>MC = Mentor Course</em></li>' . "\n" . ' 	<li><em><strong>Por exemplo: B2ETC3M2 = Begin to ECSEL Teacher Course 3 Module 2 </strong></em></li>' . "\n" . '</ul>',
                ),
            ),

            // Pathway 28 — Teacher Phase 2.
            28 => array(
                'pathway_name' => array(
                    'en'    => 'Begin to ECSEL Training & Mastery Program (Teacher Phase 2)',
                    'es'    => 'Begin to ECSEL Programa de Capacitación y Dominio (Maestro Fase 2)',
                    'pt-br' => 'Begin to ECSEL Programa de Treinamento e Domínio (Professor Fase 2)',
                ),
                'description' => array(
                    'en'    => $shared_desc_en . "\n\n" . 'In the Phase II of the program, teachers will be provided with 4 Teacher courses.',
                    'es'    => $shared_desc_es . "\n\n" . 'En la Fase II del programa, los maestros recibirán 4 cursos de Maestro.',
                    'pt-br' => $shared_desc_pt . "\n\n" . 'Na Fase II do programa, os professores receberão 4 cursos de Professor.',
                ),
                'objectives' => array(
                    'en'    => $shared_obj_en,
                    'es'    => $shared_obj_es,
                    'pt-br' => $shared_obj_pt,
                ),
            ),

            // Pathway 31 — Streamlined Phase 2 (School Leaders).
            31 => array(
                'pathway_name' => array(
                    'en'    => 'Begin to ECSEL Training & Mastery Program (Streamlined Phase 2)',
                    'es'    => 'Begin to ECSEL Programa de Capacitación y Dominio (Fase 2 Simplificada)',
                    'pt-br' => 'Begin to ECSEL Programa de Treinamento e Domínio (Fase 2 Simplificada)',
                ),
                'description' => array(
                    'en'    => $shared_desc_en . $streamlined_p2_en,
                    'es'    => $shared_desc_es . $streamlined_p2_es,
                    'pt-br' => $shared_desc_pt . $streamlined_p2_pt,
                ),
                'objectives' => array(
                    'en'    => $shared_obj_en,
                    'es'    => $shared_obj_es,
                    'pt-br' => $shared_obj_pt,
                ),
            ),

            // Pathway 33 — Mentor Phase 2.
            33 => array(
                'pathway_name' => array(
                    'en'    => 'Begin to ECSEL Training & Mastery Program (Mentor Phase 2)',
                    'es'    => 'Begin to ECSEL Programa de Capacitación y Dominio (Mentor Fase 2)',
                    'pt-br' => 'Begin to ECSEL Programa de Treinamento e Domínio (Mentor Fase 2)',
                ),
                'description' => array(
                    'en'    => $shared_desc_en . "\n\n" . 'In the Phase II of the program, mentors will be provided with 4 Teacher courses and 2 additional Mentor-focused Courses.',
                    'es'    => $shared_desc_es . "\n\n" . 'En la Fase II del programa, los mentores recibirán 4 cursos de Maestro y 2 cursos adicionales enfocados en Mentor.',
                    'pt-br' => $shared_desc_pt . "\n\n" . 'Na Fase II do programa, os mentores receberão 4 cursos de Professor e 2 cursos adicionais focados em Mentor.',
                ),
                'objectives' => array(
                    'en'    => $shared_obj_en,
                    'es'    => $shared_obj_es,
                    'pt-br' => $shared_obj_pt,
                ),
            ),

            // Pathway 35 — Mentor Transition.
            35 => array(
                'pathway_name' => array(
                    'en'    => 'Begin to ECSEL Training & Mastery Program (Mentor Transition)',
                    'es'    => 'Begin to ECSEL Programa de Capacitación y Dominio (Transición de Mentor)',
                    'pt-br' => 'Begin to ECSEL Programa de Treinamento e Domínio (Mentor Transição)',
                ),
                'description' => array(
                    'en'    => $shared_desc_en . "\n\n" . 'The Mentor Transition phase is specifically for a returning <em>begin to</em> ECSEL teacher being promoted to a mentor. In this phase, the new mentor will be provided with 4 Teacher courses from Phase 2 and 2 additional Mentor-focused Courses from Phase 1.',
                    'es'    => $shared_desc_es . "\n\n" . 'La fase de Transición de Mentor es específicamente para un maestro de <em>begin to</em> ECSEL que regresa y está siendo promovido a mentor. En esta fase, el nuevo mentor recibirá 4 cursos de Maestro de la Fase 2 y 2 cursos adicionales enfocados en Mentor de la Fase 1.',
                    'pt-br' => $shared_desc_pt . "\n\n" . 'A fase de Transição para Mentor é especificamente para um professor <em>begin to</em> ECSEL que está retornando e sendo promovido a mentor. Nesta fase, o novo mentor receberá 4 cursos de Professor da Fase 2 e 2 cursos adicionais focados em Mentor da Fase 1.',
                ),
                'objectives' => array(
                    'en'    => $shared_obj_en,
                    'es'    => $shared_obj_es,
                    'pt-br' => $shared_obj_pt,
                ),
            ),

            // Pathway 37 — Streamlined Phase 2 (District Leaders).
            37 => array(
                'pathway_name' => array(
                    'en'    => 'Begin to ECSEL Training & Mastery Program (Streamlined Phase 2)',
                    'es'    => 'Begin to ECSEL Programa de Capacitación y Dominio (Fase 2 Simplificada)',
                    'pt-br' => 'Begin to ECSEL Programa de Treinamento e Domínio (Fase 2 Simplificada)',
                ),
                'description' => array(
                    'en'    => $shared_desc_en . $streamlined_p2_en,
                    'es'    => $shared_desc_es . $streamlined_p2_es,
                    'pt-br' => $shared_desc_pt . $streamlined_p2_pt,
                ),
                'objectives' => array(
                    'en'    => $shared_obj_en,
                    'es'    => $shared_obj_es,
                    'pt-br' => $shared_obj_pt,
                ),
            ),
        );
    }

    // =========================================================================
    // Part 2: TSA Instrument Translations
    // =========================================================================

    private static function translate_tsa_instruments( $dry_run ) {
        WP_CLI::log( "\n--- Part 2: TSA Instrument Translations ---" );

        global $wpdb;
        $table = $wpdb->prefix . 'hl_teacher_assessment_instrument';

        $languages = array(
            'es'    => self::get_tsa_pre_es(),
            'pt-br' => self::get_tsa_pre_pt_br(),
        );

        foreach ( $languages as $lang_code => $data ) {
            $key = 'b2e_self_assessment_pre_' . $lang_code;

            // Check if already exists.
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT instrument_id FROM {$table} WHERE instrument_key = %s",
                $key
            ) );

            if ( $existing ) {
                if ( $dry_run ) {
                    WP_CLI::log( "Would UPDATE existing instrument: {$key} (id={$existing})" );
                } else {
                    $wpdb->update(
                        $table,
                        array(
                            'instrument_name' => $data['instrument_name'],
                            'instructions'    => $data['instructions'],
                            'sections'        => wp_json_encode( $data['sections'] ),
                            'scale_labels'    => wp_json_encode( $data['scale_labels'] ),
                        ),
                        array( 'instrument_id' => $existing ),
                        array( '%s', '%s', '%s', '%s' ),
                        array( '%d' )
                    );
                    WP_CLI::log( "Updated instrument: {$key} (id={$existing})" );
                }
            } else {
                if ( $dry_run ) {
                    WP_CLI::log( "Would INSERT new instrument: {$key}" );
                    WP_CLI::log( "  Name: {$data['instrument_name']}" );
                    WP_CLI::log( "  Sections: " . count( $data['sections'] ) . " sections" );
                } else {
                    $wpdb->insert(
                        $table,
                        array(
                            'instrument_name'    => $data['instrument_name'],
                            'instrument_version' => '1.0',
                            'instrument_key'     => $key,
                            'sections'           => wp_json_encode( $data['sections'] ),
                            'scale_labels'       => wp_json_encode( $data['scale_labels'] ),
                            'instructions'       => $data['instructions'],
                            'styles_json'        => wp_json_encode( array(
                                'instructions_font_size' => '15px',
                                'instructions_color'     => '#000000',
                                'section_desc_font_size' => '14px',
                            ) ),
                            'status'             => 'active',
                        ),
                        array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
                    );
                    $new_id = $wpdb->insert_id;
                    WP_CLI::log( "Inserted instrument: {$key} (id={$new_id})" );
                }
            }
        }

        WP_CLI::log( 'TSA instrument translations complete.' );
    }

    /**
     * Spanish (es_MX) TSA Pre instrument data.
     */
    private static function get_tsa_pre_es() {
        return array(
            'instrument_name' => 'Autoevaluación del Maestro',
            'instructions'    => 'Este cuestionario consta de tres evaluaciones sobre sus prácticas de enseñanza actuales, su entorno de trabajo y cómo maneja las emociones en la vida diaria. Tomará aproximadamente 15 minutos completarlo. No hay respuestas correctas ni incorrectas. Esta evaluación es para la reflexión y el crecimiento, no para la evaluación de desempeño. No utilizaremos sus respuestas individuales en ningún informe, solo los resultados grupales.',
            'sections'        => array(
                array(
                    'section_key' => 'practices',
                    'title'       => 'Evaluación 1',
                    'description' => 'Por favor piense en su <strong>práctica típica en días difíciles</strong> (por ejemplo, cuando los niños están desregulados, las transiciones son difíciles o usted se siente estresado/a o abrumado/a). Para cada enunciado, califique cómo ha estado respondiendo típicamente durante las <strong>últimas dos semanas</strong>.',
                    'type'        => 'likert',
                    'scale_key'   => 'practices_5',
                    'items'       => array(
                        array( 'key' => 'P1',  'text' => 'Cuando comienzo a sentirme estresado/a o frustrado/a, noto señales tempranas en mi cuerpo o emociones (por ejemplo, tensión, voz elevada, prisa).' ),
                        array( 'key' => 'P2',  'text' => 'Cuando noto que me estoy desregulando, uso estrategias (pausa, respiración, diálogo interno, dar un paso atrás) para mantener la calma antes de responder a los niños.' ),
                        array( 'key' => 'P3',  'text' => 'Durante momentos emocionalmente intensos, les hablo a los niños en un tono calmado y uso lenguaje corporal controlado, incluso cuando me siento alterado/a por dentro.' ),
                        array( 'key' => 'P4',  'text' => 'Cuando un niño está alterado, reconozco o nombro los sentimientos del niño antes de redirigir o resolver el problema.' ),
                        array( 'key' => 'P5',  'text' => 'Apoyo activamente a los niños para que calmen su cuerpo y sus emociones (por ejemplo, respirando juntos, ofreciendo herramientas sensoriales, sentándome cerca).' ),
                        array( 'key' => 'P6',  'text' => 'Mis respuestas a las emociones fuertes o comportamientos de los niños generalmente ayudan al niño a calmarse, comprender las expectativas o volver a participar positivamente.' ),
                        array( 'key' => 'P7',  'text' => 'Modelo empatía, pensamiento flexible y comunicación respetuosa cuando surgen desafíos (por ejemplo, narrando mi pensamiento, nombrando sentimientos).' ),
                        array( 'key' => 'P8',  'text' => 'Hago preguntas apropiadas para el desarrollo que ayudan a los niños a reflexionar sobre sus sentimientos, acciones y decisiones.' ),
                        array( 'key' => 'P9',  'text' => 'Trato las situaciones emocionales (sentimientos intensos, errores, conflictos) como oportunidades para enseñar habilidades sociales y emocionales.' ),
                        array( 'key' => 'P10', 'text' => 'Cuando surgen conflictos, guío a los niños para que expresen sus necesidades, escuchen a los demás y generen soluciones en lugar de resolver el problema por ellos.' ),
                        array( 'key' => 'P11', 'text' => 'Cuando las emociones o comportamientos de los niños se intensifican, me siento capaz de desacelerar la situación y responder intencionalmente en lugar de reactivamente.' ),
                        array( 'key' => 'P12', 'text' => 'Doy instrucciones claras y apropiadas para el desarrollo y ajusto mi apoyo cuando los niños parecen confundidos o abrumados.' ),
                        array( 'key' => 'P13', 'text' => 'Ajusto las expectativas, estrategias o el entorno para satisfacer las necesidades sociales, emocionales, conductuales y de aprendizaje individuales y grupales.' ),
                        array( 'key' => 'P14', 'text' => 'Mantengo rutinas y transiciones consistentes que ayudan a los niños a sentirse seguros y saber qué esperar, incluso en días ocupados o estresantes.' ),
                        array( 'key' => 'P15', 'text' => 'Creo regularmente oportunidades para que los niños noten y compartan cómo se sienten (por ejemplo, registro matutino, tablas de emociones, discusiones en grupo).' ),
                        array( 'key' => 'P16', 'text' => 'Integro intencionalmente habilidades de Inteligencia Emocional en el juego, la exploración, los cuentos y las actividades de aprendizaje diarias.' ),
                        array( 'key' => 'P17', 'text' => 'Me siento capaz de discutir respetuosamente preocupaciones o desafíos con las familias, compañeros de trabajo o supervisores.' ),
                        array( 'key' => 'P18', 'text' => 'Cuando no estoy seguro/a o tengo dificultades, busco orientación o apoyo de un colega o supervisor de confianza.' ),
                        array( 'key' => 'P19', 'text' => 'Después de momentos difíciles, reflexiono sobre lo que sucedió y considero qué podría intentar de manera diferente la próxima vez.' ),
                        array( 'key' => 'P20', 'text' => 'Me siento seguro/a de mis habilidades como educador/a, generalmente satisfecho/a con mi trabajo y conectado/a con su propósito, incluso cuando el trabajo se siente difícil.' ),
                    ),
                ),
                array(
                    'section_key' => 'wellbeing',
                    'title'       => 'Evaluación 2',
                    'description' => 'Pensando en las últimas dos semanas, responda las siguientes preguntas calificando cada elemento en una escala de 0 "para nada" a 10 "mucho".',
                    'type'        => 'scale',
                    'scale_key'   => 'scale_0_10',
                    'items'       => array(
                        array( 'key' => 'W1', 'text' => '¿Qué tan estresante es su trabajo?',                                          'left_anchor' => 'Para nada estresante',   'right_anchor' => 'Muy estresante' ),
                        array( 'key' => 'W2', 'text' => '¿Qué tan bien está manejando el estrés de su trabajo en este momento?',        'left_anchor' => 'No lo manejo para nada', 'right_anchor' => 'Lo manejo muy bien' ),
                        array( 'key' => 'W3', 'text' => '¿Qué tan apoyado/a se siente en su trabajo?',                                 'left_anchor' => 'Para nada apoyado/a',    'right_anchor' => 'Muy apoyado/a' ),
                        array( 'key' => 'W4', 'text' => '¿Qué tan satisfecho/a está con su trabajo?',                                  'left_anchor' => 'Para nada satisfecho/a', 'right_anchor' => 'Muy satisfecho/a' ),
                    ),
                ),
                array(
                    'section_key' => 'self_regulation',
                    'title'       => 'Evaluación 3',
                    'description' => 'Marque en qué medida está de acuerdo o en desacuerdo con cada uno de los enunciados.',
                    'type'        => 'likert',
                    'scale_key'   => 'likert_7',
                    'items'       => array(
                        array( 'key' => 'SR1', 'text' => 'Soy capaz de controlar mi temperamento para poder manejar las dificultades de manera racional.' ),
                        array( 'key' => 'SR2', 'text' => 'Soy bastante capaz de controlar mis propias emociones.' ),
                        array( 'key' => 'SR3', 'text' => 'Siempre puedo calmarme rápidamente cuando estoy muy enojado/a.' ),
                        array( 'key' => 'SR4', 'text' => 'Tengo buen control de mis emociones.' ),
                    ),
                ),
            ),
            'scale_labels' => array(
                'practices_5' => array( 'Casi nunca', 'Raramente', 'A veces', 'Frecuentemente', 'Casi siempre' ),
                'likert_7'    => array( 'Totalmente en desacuerdo', 'En desacuerdo', 'Ligeramente en desacuerdo', 'Ni de acuerdo ni en desacuerdo', 'Ligeramente de acuerdo', 'De acuerdo', 'Totalmente de acuerdo' ),
                'scale_0_10'  => array( 'low' => 'Para nada', 'high' => 'Mucho' ),
            ),
        );
    }

    /**
     * Portuguese (pt_BR) TSA Pre instrument data.
     */
    private static function get_tsa_pre_pt_br() {
        return array(
            'instrument_name' => 'Autoavaliação do Professor',
            'instructions'    => 'Este questionário consiste em três avaliações sobre suas práticas pedagógicas atuais, seu ambiente de trabalho e como você gerencia emoções no dia a dia. Levará aproximadamente 15 minutos para ser concluído. Não há respostas certas ou erradas. Esta avaliação é para reflexão e crescimento, não para avaliação de desempenho. Não utilizaremos suas respostas individuais em nenhum relatório, apenas resultados do grupo.',
            'sections'        => array(
                array(
                    'section_key' => 'practices',
                    'title'       => 'Avaliação 1',
                    'description' => 'Pense em sua <strong>prática típica em dias difíceis</strong> (por exemplo, quando as crianças estão desreguladas, as transições são difíceis ou você se sente estressado(a) ou sobrecarregado(a)). Para cada afirmação, avalie como você tem respondido tipicamente nas <strong>últimas duas semanas</strong>.',
                    'type'        => 'likert',
                    'scale_key'   => 'practices_5',
                    'items'       => array(
                        array( 'key' => 'P1',  'text' => 'Quando começo a me sentir estressado(a) ou frustrado(a), percebo os primeiros sinais no meu corpo ou emoções (por exemplo, tensão, voz alterada, pressa).' ),
                        array( 'key' => 'P2',  'text' => 'Quando percebo que estou ficando desregulado(a), uso estratégias (pausa, respiração, diálogo interno, recuar) para manter a calma antes de responder às crianças.' ),
                        array( 'key' => 'P3',  'text' => 'Durante momentos emocionalmente intensos, falo com as crianças em tom calmo e uso linguagem corporal controlada, mesmo quando me sinto perturbado(a) por dentro.' ),
                        array( 'key' => 'P4',  'text' => 'Quando uma criança está chateada, eu reconheço ou nomeio os sentimentos da criança antes de redirecionar ou resolver o problema.' ),
                        array( 'key' => 'P5',  'text' => 'Apoio ativamente as crianças a acalmarem seus corpos e emoções (por exemplo, respirando juntos, oferecendo ferramentas sensoriais, sentando perto).' ),
                        array( 'key' => 'P6',  'text' => 'Minhas respostas às emoções fortes ou comportamentos das crianças geralmente ajudam a criança a se acalmar, entender expectativas ou se reengajar positivamente.' ),
                        array( 'key' => 'P7',  'text' => 'Eu modelo empatia, pensamento flexível e comunicação respeitosa quando surgem desafios (por exemplo, narrando meu pensamento, nomeando sentimentos).' ),
                        array( 'key' => 'P8',  'text' => 'Faço perguntas adequadas ao desenvolvimento que ajudam as crianças a refletir sobre seus sentimentos, ações e escolhas.' ),
                        array( 'key' => 'P9',  'text' => 'Trato situações emocionais (sentimentos intensos, erros, conflitos) como oportunidades para ensinar habilidades sociais e emocionais.' ),
                        array( 'key' => 'P10', 'text' => 'Quando surgem conflitos, oriento as crianças a expressar necessidades, ouvir os outros e gerar soluções em vez de resolver o problema por elas.' ),
                        array( 'key' => 'P11', 'text' => 'Quando as emoções ou comportamentos das crianças se intensificam, sinto-me capaz de desacelerar a situação e responder intencionalmente em vez de reativamente.' ),
                        array( 'key' => 'P12', 'text' => 'Dou instruções claras e adequadas ao desenvolvimento e ajusto meu apoio quando as crianças parecem confusas ou sobrecarregadas.' ),
                        array( 'key' => 'P13', 'text' => 'Ajusto expectativas, estratégias ou o ambiente para atender às necessidades sociais, emocionais, comportamentais e de aprendizagem individuais e do grupo.' ),
                        array( 'key' => 'P14', 'text' => 'Mantenho rotinas e transições consistentes que ajudam as crianças a se sentirem seguras e saberem o que esperar, mesmo em dias agitados ou estressantes.' ),
                        array( 'key' => 'P15', 'text' => 'Crio regularmente oportunidades para as crianças perceberem e compartilharem como se sentem (por exemplo, acolhimento matinal, quadros de emoções, discussões em grupo).' ),
                        array( 'key' => 'P16', 'text' => 'Integro intencionalmente habilidades de Inteligência Emocional em brincadeiras, explorações, histórias e atividades diárias de aprendizagem.' ),
                        array( 'key' => 'P17', 'text' => 'Sinto-me capaz de discutir respeitosamente preocupações ou desafios com famílias, colegas de trabalho ou supervisores.' ),
                        array( 'key' => 'P18', 'text' => 'Quando estou inseguro(a) ou com dificuldades, busco orientação ou apoio de um colega ou supervisor de confiança.' ),
                        array( 'key' => 'P19', 'text' => 'Após momentos desafiadores, reflito sobre o que aconteceu e considero o que poderia tentar de forma diferente na próxima vez.' ),
                        array( 'key' => 'P20', 'text' => 'Sinto-me confiante em minhas habilidades como educador(a), geralmente satisfeito(a) com meu trabalho e conectado(a) ao seu propósito — mesmo quando o trabalho parece difícil.' ),
                    ),
                ),
                array(
                    'section_key' => 'wellbeing',
                    'title'       => 'Avaliação 2',
                    'description' => 'Pensando nas últimas duas semanas, responda as seguintes perguntas avaliando cada item em uma escala de 0 "de forma alguma" a 10 "Muito".',
                    'type'        => 'scale',
                    'scale_key'   => 'scale_0_10',
                    'items'       => array(
                        array( 'key' => 'W1', 'text' => 'Quão estressante é o seu trabalho?',                                          'left_anchor' => 'Nada Estressante',    'right_anchor' => 'Muito Estressante' ),
                        array( 'key' => 'W2', 'text' => 'Quão bem você está lidando com o estresse do seu trabalho neste momento?',     'left_anchor' => 'Não Estou Lidando',   'right_anchor' => 'Lidando Muito Bem' ),
                        array( 'key' => 'W3', 'text' => 'Quão apoiado(a) você se sente no seu trabalho?',                              'left_anchor' => 'Nada Apoiado(a)',     'right_anchor' => 'Muito Apoiado(a)' ),
                        array( 'key' => 'W4', 'text' => 'Quão satisfeito(a) você está com o seu trabalho?',                            'left_anchor' => 'Nada Satisfeito(a)',  'right_anchor' => 'Muito Satisfeito(a)' ),
                    ),
                ),
                array(
                    'section_key' => 'self_regulation',
                    'title'       => 'Avaliação 3',
                    'description' => 'Indique o quanto você concorda ou discorda de cada uma das afirmações.',
                    'type'        => 'likert',
                    'scale_key'   => 'likert_7',
                    'items'       => array(
                        array( 'key' => 'SR1', 'text' => 'Sou capaz de controlar meu temperamento para lidar com dificuldades de forma racional.' ),
                        array( 'key' => 'SR2', 'text' => 'Sou bastante capaz de controlar minhas próprias emoções.' ),
                        array( 'key' => 'SR3', 'text' => 'Consigo sempre me acalmar rapidamente quando estou com muita raiva.' ),
                        array( 'key' => 'SR4', 'text' => 'Tenho bom controle das minhas emoções.' ),
                    ),
                ),
            ),
            'scale_labels' => array(
                'practices_5' => array( 'Quase Nunca', 'Raramente', 'Às Vezes', 'Frequentemente', 'Quase Sempre' ),
                'likert_7'    => array( 'Discordo Totalmente', 'Discordo', 'Discordo Levemente', 'Não Concordo Nem Discordo', 'Concordo Levemente', 'Concordo', 'Concordo Totalmente' ),
                'scale_0_10'  => array( 'low' => 'De forma alguma', 'high' => 'Muito' ),
            ),
        );
    }

    // =========================================================================
    // Part 3: Child Assessment Instrument Translations
    // =========================================================================

    private static function translate_child_instruments( $dry_run ) {
        WP_CLI::log( "\n--- Part 3: Child Assessment Instrument Translations ---" );

        global $wpdb;
        $table = $wpdb->prefix . 'hl_instrument';

        // Source ELCPB instruments (ids 13-15 on production).
        $base_instruments = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE name LIKE 'ELCPB%' AND instrument_type LIKE 'children_%'
             AND instrument_type NOT LIKE '%\\_es' AND instrument_type NOT LIKE '%\\_pt-br'
             ORDER BY instrument_id"
        );

        if ( empty( $base_instruments ) ) {
            WP_CLI::warning( 'No ELCPB child instruments found.' );
            return;
        }

        $translations = self::get_child_instrument_translations();

        foreach ( $base_instruments as $base ) {
            WP_CLI::log( "Base: {$base->name} (type={$base->instrument_type}, id={$base->instrument_id})" );

            foreach ( $translations as $lang_code => $lang_data ) {
                $translated_type = $base->instrument_type . '_' . $lang_code;
                $translated_name = $lang_data['name_prefix'] . ' ' . ucfirst( str_replace( 'children_', '', $base->instrument_type ) ) . ' Assessment';

                // Map age band for name.
                $age_labels = $lang_data['age_labels'];
                $age_band   = str_replace( 'children_', '', $base->instrument_type );
                if ( isset( $age_labels[ $age_band ] ) ) {
                    $translated_name = $lang_data['name_prefix'] . ' ' . $age_labels[ $age_band ];
                }

                // Build translated questions JSON.
                $base_questions = json_decode( $base->questions, true );
                $translated_questions = array();
                foreach ( $base_questions as $q ) {
                    $tq = $q;
                    $prompt_key = isset( $q['prompt'] ) ? 'prompt' : 'prompt_text';
                    $tq[ $prompt_key ] = $lang_data['question_prompt'];
                    $translated_questions[] = $tq;
                }

                // Check if already exists.
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT instrument_id FROM {$table} WHERE instrument_type = %s",
                    $translated_type
                ) );

                if ( $existing ) {
                    if ( $dry_run ) {
                        WP_CLI::log( "  Would UPDATE {$lang_code}: {$translated_name} (type={$translated_type})" );
                    } else {
                        $wpdb->update( $table, array(
                            'name'      => $translated_name,
                            'questions' => wp_json_encode( $translated_questions ),
                        ), array( 'instrument_id' => $existing ) );
                        WP_CLI::log( "  Updated {$lang_code}: {$translated_name} (id={$existing})" );
                    }
                } else {
                    if ( $dry_run ) {
                        WP_CLI::log( "  Would INSERT {$lang_code}: {$translated_name} (type={$translated_type})" );
                    } else {
                        $wpdb->insert( $table, array(
                            'instrument_uuid' => wp_generate_uuid4(),
                            'name'            => $translated_name,
                            'instrument_type' => $translated_type,
                            'version'         => $base->version,
                            'questions'       => wp_json_encode( $translated_questions ),
                            'behavior_key'    => $base->behavior_key,
                            'instructions'    => $base->instructions,
                            'styles_json'     => $base->styles_json,
                            'effective_from'  => $base->effective_from,
                            'effective_to'    => $base->effective_to,
                        ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
                        $new_id = $wpdb->insert_id;
                        WP_CLI::log( "  Inserted {$lang_code}: {$translated_name} (id={$new_id})" );
                    }
                }
            }
        }

        WP_CLI::log( 'Child instrument translations complete.' );
    }

    // =========================================================================
    // Part 4: ELCPB-Specific TSA Post Instrument
    // =========================================================================

    private static function create_elcpb_tsa_post( $dry_run ) {
        WP_CLI::log( "\n--- Part 4: ELCPB TSA Post Instrument ---" );

        global $wpdb;
        $table = $wpdb->prefix . 'hl_teacher_assessment_instrument';
        $key   = 'elcpb_self_assessment_post';

        $data = self::get_elcpb_tsa_post_data();

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT instrument_id FROM {$table} WHERE instrument_key = %s",
            $key
        ) );

        if ( $existing ) {
            if ( $dry_run ) {
                WP_CLI::log( "Would UPDATE existing: {$key} (id={$existing})" );
            } else {
                $wpdb->update( $table, array(
                    'instrument_name' => $data['instrument_name'],
                    'instructions'    => $data['instructions'],
                    'sections'        => wp_json_encode( $data['sections'] ),
                    'scale_labels'    => wp_json_encode( $data['scale_labels'] ),
                ), array( 'instrument_id' => $existing ) );
                WP_CLI::log( "Updated: {$key} (id={$existing})" );
            }
        } else {
            if ( $dry_run ) {
                WP_CLI::log( "Would INSERT: {$key}" );
                WP_CLI::log( "  Name: {$data['instrument_name']}" );
                WP_CLI::log( "  Sections: " . count( $data['sections'] ) );
            } else {
                $wpdb->insert( $table, array(
                    'instrument_name'    => $data['instrument_name'],
                    'instrument_version' => '1.0',
                    'instrument_key'     => $key,
                    'sections'           => wp_json_encode( $data['sections'] ),
                    'scale_labels'       => wp_json_encode( $data['scale_labels'] ),
                    'instructions'       => $data['instructions'],
                    'styles_json'        => wp_json_encode( array(
                        'instructions_font_size' => '15px',
                        'section_desc_font_size' => '14px',
                    ) ),
                    'status' => 'active',
                ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
                $new_id = $wpdb->insert_id;
                WP_CLI::log( "Inserted: {$key} (id={$new_id})" );

                // Update ELCPBC Post TSA components to point to this instrument.
                $updated = $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}hl_component
                     SET external_ref = JSON_SET(external_ref, '$.teacher_instrument_id', %d)
                     WHERE component_type = 'teacher_self_assessment'
                     AND JSON_UNQUOTE(JSON_EXTRACT(external_ref, '$.phase')) = 'post'
                     AND pathway_id IN (
                         SELECT pathway_id FROM {$wpdb->prefix}hl_pathway WHERE cycle_id = 8
                     )",
                    $new_id
                ) );
                WP_CLI::log( "  Updated {$updated} ELCPBC Post components to instrument_id={$new_id}" );
            }
        }

        WP_CLI::log( 'ELCPB TSA Post instrument complete.' );
    }

    /**
     * ELCPB-specific TSA Post instrument data (5 sections).
     *
     * Based on: B2E Teacher Evaluation_FINAL for PB_20260203.pdf
     */
    private static function get_elcpb_tsa_post_data() {
        return array(
            'instrument_name' => 'Teacher Self-Assessment (ELCPB Post)',
            'instructions'    => 'This questionnaire consists of five assessments about your past and current instructional practices, work environment, and how you manage emotions in daily life. It will take approximately 25 minutes to complete. There are no right or wrong answers. This assessment is for reflection and growth, not evaluation. We will not use your individual answers in any report, only group results.',
            'sections'        => array(
                // Assessment 1/5 — Practices (20 items, retrospective).
                array(
                    'section_key'   => 'practices',
                    'title'         => 'Assessment 1/5',
                    'description'   => 'Please think about your <strong>typical practice on hard days</strong> (e.g., when children are dysregulated, transitions are difficult, or you feel stressed or overwhelmed). For each statement, rate yourself twice:<br><strong>- Before the b2E program:</strong> Based on what you know now, how did you typically respond <em>before</em> participating in the current phase of the b2E program?<br><strong>- Now:</strong> How have you typically been responding over the <strong>past two weeks</strong>?',
                    'type'          => 'likert',
                    'scale_key'     => 'practices_5',
                    'retrospective' => true,
                    'before_label'  => 'Before the Program',
                    'now_label'     => 'Past Two Weeks',
                    'items'         => array(
                        array( 'key' => 'P1',  'text' => 'When I begin to feel stressed or frustrated, I notice early signs in my body or emotions (e.g., tension, raised voice, rushing).' ),
                        array( 'key' => 'P2',  'text' => 'When I notice myself becoming dysregulated, I use strategies (pause, breath, self-talk, stepping back) to stay calm before responding to children.' ),
                        array( 'key' => 'P3',  'text' => 'During emotionally charged moments, I speak to children in a calm tone and use controlled body language, even when I feel upset inside.' ),
                        array( 'key' => 'P4',  'text' => 'When a child is upset, I acknowledge or name the child\'s feelings before redirecting or problem-solving.' ),
                        array( 'key' => 'P5',  'text' => 'I actively support children to calm their bodies and emotions (e.g., breathing together, offering sensory tools, sitting close).' ),
                        array( 'key' => 'P6',  'text' => 'My responses to children\'s strong emotions or behaviors usually help the child calm, understand expectations, or re-engage positively.' ),
                        array( 'key' => 'P7',  'text' => 'I model empathy, flexible thinking, and respectful communication when challenges arise (e.g., narrating my thinking, naming feelings).' ),
                        array( 'key' => 'P8',  'text' => 'I ask developmentally appropriate questions that help children reflect on their feelings, actions, and choices.' ),
                        array( 'key' => 'P9',  'text' => 'I treat emotional situations (big feelings, mistakes, conflict) as opportunities to teach social and emotional skills.' ),
                        array( 'key' => 'P10', 'text' => 'When conflicts arise, I guide children to express needs, listen to others, and generate solutions instead of solving the problem for them.' ),
                        array( 'key' => 'P11', 'text' => 'When children\'s emotions or behaviors escalate, I feel able to slow the situation down and respond intentionally rather than reactively.' ),
                        array( 'key' => 'P12', 'text' => 'I give clear, developmentally appropriate instructions and adjust my support when children seem confused or overwhelmed.' ),
                        array( 'key' => 'P13', 'text' => 'I adjust expectations, strategies, or the environment to meet individual and group social, emotional, behavioral, and learning needs.' ),
                        array( 'key' => 'P14', 'text' => 'I maintain consistent routines and transitions that help children feel safe and know what to expect, even on busy or stressful days.' ),
                        array( 'key' => 'P15', 'text' => 'I regularly create opportunities for children to notice and share how they feel (e.g., morning check-ins, emotion charts, group discussions).' ),
                        array( 'key' => 'P16', 'text' => 'I intentionally integrate emotional intelligence skills into play, exploration, stories, and daily learning activities.' ),
                        array( 'key' => 'P17', 'text' => 'I feel able to respectfully discuss concerns or challenges with families, coworkers, or supervisors.' ),
                        array( 'key' => 'P18', 'text' => 'When I am unsure or struggling, I seek guidance or support from a trusted colleague or supervisor.' ),
                        array( 'key' => 'P19', 'text' => 'After challenging moments, I reflect on what happened and consider what I might try differently next time.' ),
                        array( 'key' => 'P20', 'text' => 'I feel confident in my abilities as an educator, generally satisfied with my work, and connected to its purpose--even when the job feels hard.' ),
                    ),
                ),

                // Assessment 2/5 — Wellbeing (4 items, 0-10 scale).
                array(
                    'section_key' => 'wellbeing',
                    'title'       => 'Assessment 2/5',
                    'description' => 'Thinking about the past two weeks, answer the following questions rating each item on a scale of 0 "not at all" to 10 "Very".',
                    'type'        => 'scale',
                    'scale_key'   => 'scale_0_10',
                    'items'       => array(
                        array( 'key' => 'W1', 'text' => 'How stressful is your job?',                                        'left_anchor' => 'Not at all Stressful', 'right_anchor' => 'Very Stressful' ),
                        array( 'key' => 'W2', 'text' => 'How well are you coping with the stress of your job right now?',     'left_anchor' => 'Not Coping at all',    'right_anchor' => 'Coping Very Well' ),
                        array( 'key' => 'W3', 'text' => 'How supported do you feel in your job?',                             'left_anchor' => 'Not at all Supported', 'right_anchor' => 'Very Supported' ),
                        array( 'key' => 'W4', 'text' => 'How satisfied are you in your job?',                                 'left_anchor' => 'Not at all Satisfied', 'right_anchor' => 'Very Satisfied' ),
                    ),
                ),

                // Assessment 3/5 — Self-Regulation (4 items, 7-point Likert).
                array(
                    'section_key' => 'self_regulation',
                    'title'       => 'Assessment 3/5',
                    'description' => 'Mark the extent to which you agree or disagree with each of the statements.',
                    'type'        => 'likert',
                    'scale_key'   => 'likert_7',
                    'items'       => array(
                        array( 'key' => 'SR1', 'text' => 'I am able to control my temper so that I can handle difficulties rationally.' ),
                        array( 'key' => 'SR2', 'text' => 'I am quite capable of controlling my own emotions.' ),
                        array( 'key' => 'SR3', 'text' => 'I can always calm down quickly when I am very angry.' ),
                        array( 'key' => 'SR4', 'text' => 'I have good control of my emotions.' ),
                    ),
                ),

                // Assessment 4/5 — B2E Program Impacts (6 items, 7-point Likert).
                array(
                    'section_key' => 'program_impacts',
                    'title'       => 'Assessment 4/5',
                    'description' => 'Think about your experience implementing the <em>Begin to</em> ECSEL Program. Rate your level of agreement with each of the following questions.',
                    'type'        => 'likert',
                    'scale_key'   => 'likert_7',
                    'items'       => array(
                        array( 'key' => 'PI1', 'text' => 'I have benefitted from participating in the B2E program.' ),
                        array( 'key' => 'PI2', 'text' => 'B2E has had a positive impact on my students\' emotional development.' ),
                        array( 'key' => 'PI3', 'text' => 'B2E has had a positive impact on my students\' prosocial behavior.' ),
                        array( 'key' => 'PI4', 'text' => 'B2E has had a positive impact on my students\' ability to solve problems.' ),
                        array( 'key' => 'PI5', 'text' => 'B2E has reduced behavioral problems in my classroom.' ),
                        array( 'key' => 'PI6', 'text' => 'B2E has had a positive impact on our classroom climate.' ),
                    ),
                ),

                // Assessment 5/5 — B2E Course Reflections (5 open-ended text items).
                array(
                    'section_key' => 'course_reflections',
                    'title'       => 'Assessment 5/5: B2E Course Reflections',
                    'description' => '',
                    'type'        => 'text',
                    'items'       => array(
                        array( 'key' => 'CR1', 'text' => 'What changes have you observed in the classroom learning environment since introducing the begin to ECSEL Training & Mastery Program?' ),
                        array( 'key' => 'CR2', 'text' => 'What changes have you observed in your students\' behaviors since introducing the begin to ECSEL Training & Mastery Program?' ),
                        array( 'key' => 'CR3', 'text' => 'What changes have you observed in the time you spend teaching vs. managing children\'s dysregulated emotions since introducing the begin to ECSEL Training & Mastery Program?' ),
                        array( 'key' => 'CR4', 'text' => 'What changes have you experienced in your job satisfaction, stress level, and/or emotional wellbeing changed, if at all, since introducing the begin to ECSEL Training & Mastery Program?' ),
                        array( 'key' => 'CR5', 'text' => 'What else would you like to share about your experience with the begin to ECSEL Training & Mastery Program?' ),
                    ),
                ),
            ),
            'scale_labels' => array(
                'practices_5' => array( 'Almost Never', 'Rarely', 'Sometimes', 'Often', 'Almost Always' ),
                'likert_7'    => array( 'Strongly Disagree', 'Disagree', 'Slightly Disagree', 'Neither Agree nor Disagree', 'Slightly Agree', 'Agree', 'Strongly Agree' ),
                'scale_0_10'  => array( 'low' => 'Not at all', 'high' => 'Very' ),
            ),
        );
    }

    /**
     * Translation data for child assessment instruments.
     */
    private static function get_child_instrument_translations() {
        return array(
            'es' => array(
                'name_prefix'     => 'ELCPB',
                'age_labels'      => array(
                    'infant'    => 'Evaluación de Lactantes',
                    'toddler'   => 'Evaluación de Niños Pequeños',
                    'preschool' => 'Evaluación Preescolar',
                    'k2'        => 'Evaluación K-2',
                ),
                'question_prompt' => 'En el último mes, ¿con qué frecuencia el niño demostró que comprendía, expresaba y manejaba sus propias emociones de manera exitosa en sus interacciones con los demás?',
            ),
            'pt-br' => array(
                'name_prefix'     => 'ELCPB',
                'age_labels'      => array(
                    'infant'    => 'Avaliação de Bebês',
                    'toddler'   => 'Avaliação de Crianças Pequenas',
                    'preschool' => 'Avaliação Pré-Escolar',
                    'k2'        => 'Avaliação K-2',
                ),
                'question_prompt' => 'No último mês, com que frequência a criança demonstrou que compreendeu, expressou e gerenciou suas próprias emoções com sucesso em suas interações com os outros?',
            ),
        );
    }
}
