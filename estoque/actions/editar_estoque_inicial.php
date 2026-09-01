<?php
require __DIR__ . "/../../config/conexao.php";

$id = $_GET['id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $quantidade = $_POST['quantidade'];

    $stmt = $conexao->prepare("
UPDATE estoque_inicial
SET quantidade = ?
WHERE id = ?
");

    $stmt->bind_param("di", $quantidade, $id);
    $stmt->execute();

    header("Location: ../estoque_inicial_aba.php");
}

$dados = $conexao->query("
SELECT e.*, p.nome
FROM estoque_inicial e
JOIN produtos p ON e.produto_id = p.id
WHERE e.id = $id
")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Estoque Inicial</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f4f6f9;
            color: #222;

        }

        /* HEADER */
        .header {
            background: #1b5e20;
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
        }

        .header-left {
            display: flex;
            flex-direction: column;
        }

        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        .user-info {
            font-size: 14px;
            margin-top: 4px;
        }

        .user-info a {
            color: white;
            text-decoration: none;
            margin-left: 6px;
            font-weight: bold;
        }

        .btn-voltar {
            background: white;
            color: #1b5e20;
            padding: 6px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
            white-space: nowrap;
            align-self: flex-start;
        }

        .btn-voltar:hover {
            background: #e8f5e9;
        }

        /* CONTAINER */
        .container {
            padding: 30px;
            max-width: 1200px;
            margin: auto;
            display: flex;
            justify-content: center;
        }

        /* CARD */
        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            margin-bottom: 30px;
            width: 100%;
            max-width: 450px;
        }

        /* TITULO */
        .section-title {
            margin: 0 0 20px 0;
            font-size: 18px;
            color: #1b5e20;
            font-weight: 600;
        }

        /* FORM */
        form {
            width: 100%;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #222;
        }

        .produto-info {
            padding: 10px 12px;
            background: #f1f8f4;
            border: 1px solid #d6e9d8;
            border-radius: 6px;
            font-size: 14px;
        }

        input,
        select {
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #ccc;
            width: 100%;
            font-size: 14px;
        }

        /* BOTÕES */
        button {
            background: #2e7d32;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: 0.2s;
            font-size: 14px;
        }

        button:hover {
            background: #1b5e20;
        }

        @media (max-width:768px) {
            .header {
                align-items: flex-start;
                flex-direction: column;
            }

            .header h1 {
                font-size: 18px;
            }

            .user-info {
                font-size: 13px;
            }

            .container {
                padding: 20px;
            }

            .card {
                padding: 18px;
            }
        }
    </style>
</head>

<body>

    <div class="header">
        <div class="header-left">
            <h1>Editar Estoque Inicial</h1>
            <div class="user-info">
                Atualize a quantidade do produto selecionado
            </div>
        </div>

        <a href="../estoque_inicial_aba.php" class="btn-voltar">Voltar</a>
    </div>

    <div class="container">
        <div class="card">
            <h2>Dados do Registro</h2>

            <form method="POST">
                <div class="form-group">
                    <label>Produto</label>
                    <div class="produto-info">
                        <?= htmlspecialchars($dados['nome']) ?>
                    </div>
                </div>

                <div class="form-group">
                    <label for="quantidade">Quantidade</label>
                    <input
                        type="number"
                        step="0.01"
                        name="quantidade"
                        id="quantidade"
                        value="<?= htmlspecialchars($dados['quantidade']) ?>"
                        required>
                </div>

                <button type="submit">Salvar</button>
            </form>
        </div>
    </div>

</body>

</html>