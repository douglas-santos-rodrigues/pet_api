<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Cão' => 'Canis lupus familiaris', 'Gato' => 'Felis catus', 'Coelho' => 'Oryctolagus cuniculus'] as $nome => $cientifico) {
            DB::table('especies')->upsert([['nome' => $nome, 'nome_cientifico' => $cientifico]], ['nome'], ['nome_cientifico']);
            $id = DB::table('especies')->where('nome', $nome)->value('id_especie');
            $racas = match ($nome) {
                'Cão' => ['Labrador Retriever', 'Poodle', 'Shih Tzu'],
                'Gato' => ['Siamês', 'Persa', 'Maine Coon'],
                'Coelho' => ['Mini Lop'],
            };
            foreach ([...$racas, 'Sem raça definida (SRD)', 'Desconhecida'] as $raca) {
                DB::table('racas')->insertOrIgnore(['id_especie' => $id, 'nome' => $raca]);
            }
        }

        foreach ([
            'Consulta' => 'CLINICO', 'Retorno' => 'CLINICO', 'Vacina' => 'PREVENTIVO',
            'Reforço' => 'PREVENTIVO', 'Exame' => 'DIAGNOSTICO', 'Cirurgia' => 'CLINICO',
            'Tratamento' => 'CLINICO', 'Medicação' => 'CLINICO', 'Vermifugação' => 'PREVENTIVO',
            'Antiparasitário' => 'PREVENTIVO', 'Pesagem' => 'ACOMPANHAMENTO',
            'Internação' => 'CLINICO', 'Outros' => 'OUTROS',
        ] as $nome => $categoria) {
            DB::table('tipos_procedimento')->insertOrIgnore(['nome' => $nome, 'categoria' => $categoria]);
        }
    }
}
