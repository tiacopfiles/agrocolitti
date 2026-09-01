<?php
require __DIR__ . "/../../config/conexao.php";

$id = $_GET['id'];

// Buscar previsão
$sql = "SELECT * FROM previsao_fornecedor WHERE id = $id";
$result = $conexao->query($sql);

if($result->num_rows == 0){
    die("Previsão não encontrada.");
}

$previsao = $result->fetch_assoc();
?>

<h2>Confirmar Chegada do Fornecedor</h2>

<button onclick="abrirModal()">Confirmar Chegada</button>


<!-- MODAL 1 -->
<div id="modalPrincipal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5);">

    <div style="background:white; width:400px; margin:100px auto; padding:20px; border-radius:8px;">

        <h3>Houve abatimento?</h3>

        <p>Quantidade prevista: <?= number_format($previsao['quantidade_prevista'],2); ?> kg</p>

        <select id="selectAbatimento" onchange="verificarAbatimento()">
            <option value="nao">Não</option>
            <option value="sim">Sim</option>
        </select>

        <br><br>

        <button onclick="confirmarSemAbatimento()">Confirmar</button>
        <button onclick="fecharModal()">Cancelar</button>

    </div>
</div>


<!-- MODAL 2 -->
<div id="modalDetalhe" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5);">

    <div style="background:white; width:450px; margin:100px auto; padding:20px; border-radius:8px;">

        <h3>Detalhar Abatimento</h3>

        <form method="POST" action="finalizar_../previsao_fornecedor.php">

            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="abatimento" value="sim">

            <label>Quantidade recebida:</label>
            <input type="number" step="0.01" name="quantidade_recebida" required>

            <br><br>

            <label>Motivo do abatimento:</label>
            <textarea name="motivo_abatimento" required></textarea>

            <br><br>

            <button type="submit">Confirmar Abatimento</button>
            <button type="button" onclick="fecharDetalhe()">Cancelar</button>

        </form>

    </div>
</div>



<script>

function abrirModal(){
    document.getElementById("modalPrincipal").style.display = "block";
}

function fecharModal(){
    document.getElementById("modalPrincipal").style.display = "none";
}

function verificarAbatimento(){
    var valor = document.getElementById("selectAbatimento").value;

    if(valor === "sim"){
        fecharModal();
        document.getElementById("modalDetalhe").style.display = "block";
    }
}

function fecharDetalhe(){
    document.getElementById("modalDetalhe").style.display = "none";
}

function confirmarSemAbatimento(){

    var form = document.createElement("form");
    form.method = "POST";
    form.action = "finalizar_../previsao_fornecedor.php";

    var idInput = document.createElement("input");
    idInput.type = "hidden";
    idInput.name = "id";
    idInput.value = "<?= $id ?>";

    var abatimentoInput = document.createElement("input");
    abatimentoInput.type = "hidden";
    abatimentoInput.name = "abatimento";
    abatimentoInput.value = "nao";

    form.appendChild(idInput);
    form.appendChild(abatimentoInput);

    document.body.appendChild(form);
    form.submit();
}

</script>