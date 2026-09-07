import numpy as np
from typing import List, Union


class SimilarityService:
    """
    Mathematical similarity computation service for embedding vectors and matrices.
    """

    @staticmethod
    def cosine_similarity(v1: Union[List[float], np.ndarray], v2: Union[List[float], np.ndarray]) -> float:
        """
        Compute cosine similarity between two 1D vectors.
        Returns a float between -1.0 and 1.0 (clamped between 0.0 and 1.0 for normalized positive space).
        """
        arr1 = np.asarray(v1, dtype=np.float32)
        arr2 = np.asarray(v2, dtype=np.float32)

        norm1 = np.linalg.norm(arr1)
        norm2 = np.linalg.norm(arr2)

        if norm1 == 0 or norm2 == 0:
            return 0.0

        similarity = float(np.dot(arr1, arr2) / (norm1 * norm2))
        return float(np.clip(similarity, -1.0, 1.0))

    @staticmethod
    def compute_similarity_matrix(
        matrix_a: Union[List[List[float]], np.ndarray],
        matrix_b: Union[List[List[float]], np.ndarray],
    ) -> np.ndarray:
        """
        Compute pairwise cosine similarity matrix between two sets of vectors.
        matrix_a: shape (N, D)
        matrix_b: shape (M, D)
        Returns: numpy ndarray of shape (N, M) where item [i, j] is sim(matrix_a[i], matrix_b[j]).
        """
        arr_a = np.asarray(matrix_a, dtype=np.float32)
        arr_b = np.asarray(matrix_b, dtype=np.float32)

        if arr_a.size == 0 or arr_b.size == 0:
            return np.empty((arr_a.shape[0] if arr_a.ndim > 1 else 0, arr_b.shape[0] if arr_b.ndim > 1 else 0), dtype=np.float32)

        if arr_a.ndim == 1:
            arr_a = arr_a.reshape(1, -1)
        if arr_b.ndim == 1:
            arr_b = arr_b.reshape(1, -1)

        norm_a = np.linalg.norm(arr_a, axis=1, keepdims=True)
        norm_b = np.linalg.norm(arr_b, axis=1, keepdims=True)

        norm_a = np.where(norm_a == 0, 1e-10, norm_a)
        norm_b = np.where(norm_b == 0, 1e-10, norm_b)

        normalized_a = arr_a / norm_a
        normalized_b = arr_b / norm_b

        similarity_matrix = np.dot(normalized_a, normalized_b.T)
        return np.clip(similarity_matrix, -1.0, 1.0)

